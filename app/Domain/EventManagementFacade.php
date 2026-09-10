<?php
// Single entry point for this module's operations. Author: Goh Jian Yu

namespace App\Domain;

use App\Core\Database;
use App\FacilityStatus;
use App\Model\Event;
use App\Model\EventInvite;
use App\Model\EventInviteMapper;
use App\Model\EventMapper;
use App\Model\Facility;
use App\Model\FacilityMapper;
use App\NotFoundException;
use App\Security\Auth;
use App\Security\EventFacilitySecurity;
use App\Service\RemoteServices;
use DateTimeImmutable;

// Facade over this module's subsystem: three mappers, four policy and factory
// classes, four remote services, the ownership checks and the transaction
// boundary.
//
// Publishing an event means load it, check the caller hosts it, ask the Venue
// Booking module over HTTP whether it is paid, tell a refusal apart from an
// outage, flip the status and save. Without this class every caller - the
// controller, the REST endpoint, any admin tool - would repeat that sequence and
// be coupled to all of it. Here they call publishEvent().
//
// The rule this class keeps: it delegates and orders, it does not decide. There
// is no opening-hours arithmetic, no "is it paid", no "are they friends" below;
// each of those is asked of the class that owns it. The only conditionals here
// are "the row does not exist, raise 404".
final class EventManagementFacade
{
    private FacilityMapper $facilities;
    private EventMapper $events;
    private EventInviteMapper $invites;
    private RemoteServices $services;
    private AvailabilityChecker $availability;
    private PublicationPolicy $publication;
    private VisibilityPolicy $visibility;
    private EntityFactory $factory;
    private InviteTokens $tokens;

    public function __construct(
        ?FacilityMapper $facilities = null,
        ?EventMapper $events = null,
        ?EventInviteMapper $invites = null,
        ?RemoteServices $services = null
    ) {
        $this->facilities = $facilities ?? new FacilityMapper();
        $this->events     = $events ?? new EventMapper();
        $this->invites    = $invites ?? new EventInviteMapper();
        $this->services   = $services ?? new RemoteServices();

        $this->availability = new AvailabilityChecker($this->events);
        $this->publication  = new PublicationPolicy($this->services);
        $this->visibility   = new VisibilityPolicy($this->services);
        $this->factory      = new EntityFactory();
        $this->tokens       = new InviteTokens();
    }

    // -- facility onboarding and management ---------------------------------

    public function onboardFacility(array $validated): Facility
    {
        $owner = Auth::requireFacilityOwner();

        // Confirms the account exists in the Authentication module before a
        // venue is attached to it.
        $this->services->contactFor($owner);

        $facility = $this->factory->newFacility($validated, $owner);
        $this->facilities->insert($facility);

        return $facility;
    }

    public function updateFacility(string $facilityId, array $validated): Facility
    {
        $facility = $this->requireFacility($facilityId);

        EventFacilitySecurity::assertOwnsFacility($facility);

        $facility->updateDetails($validated);
        $this->facilities->update($facility);

        return $facility;
    }

    public function suspendFacility(string $facilityId): Facility
    {
        $facility = $this->requireFacility($facilityId);

        EventFacilitySecurity::assertOwnsFacility($facility);

        $facility->suspend();
        $this->facilities->update($facility);

        return $facility;
    }

    public function reactivateFacility(string $facilityId): Facility
    {
        $facility = $this->requireFacility($facilityId);

        EventFacilitySecurity::assertOwnsFacility($facility);

        $facility->approve();
        $this->facilities->update($facility);

        return $facility;
    }

    public function listMyFacilities(): array
    {
        return $this->facilities->findByOwner(Auth::requireFacilityOwner()->getBaseUserId());
    }

    // Removes a venue outright when nothing references it, otherwise delists it.
    // A venue with events, reviews or ratings behind it is history: deleting the
    // row would take the reviews and ratings with it, and the events would block
    // it at the foreign key anyway.
    public function removeFacility(string $facilityId): string
    {
        $facility = $this->requireFacility($facilityId);

        EventFacilitySecurity::assertOwnsFacility($facility);

        if ($this->facilities->countDependents($facilityId) > 0) {
            $facility->suspend();
            $this->facilities->update($facility);

            return 'SUSPENDED';
        }

        $this->facilities->delete($facilityId);

        return 'DELETED';
    }

    // Whether deleting really would delete. The venue list uses this to offer
    // the button only when it can do what it says - otherwise the owner presses
    // Delete and is told afterwards that it was delisted instead, which is a
    // poor way to find out.
    public function canDeleteFacility(string $facilityId): bool
    {
        return $this->facilities->countDependents($facilityId) === 0;
    }

    // -- facility search ----------------------------------------------------

    // Search is exposed as a web service for the Discovery module to build its
    // map and browse screens on. This module itself only needs the plain list
    // below, for the venue field on the event form.
    public function searchFacilities(SearchCriteria $criteria): array
    {
        return $this->facilities->search($criteria);
    }

    public function listBookableFacilities(): array
    {
        $facilities = $this->facilities->findBy(['status' => FacilityStatus::ACTIVE->value], 'name');

        return $facilities;
    }

    public function ratingsFor(array $facilities): array
    {
        return $this->services->facilityRatings(array_map(
            function ($f) { return $f->getFacilityId(); },
            $facilities
        ));
    }

    public function getFacility(string $facilityId): Facility
    {
        return $this->requireFacility($facilityId);
    }

    public function listCities(): array
    {
        return $this->facilities->listCities();
    }

    public function listTypes(): array
    {
        return $this->facilities->listTypes();
    }

    public function busySlots(string $windowEnd): array
    {
        return $this->events->busyIntervals($windowEnd);
    }

    public function findOpenSlots(string $facilityId, DateTimeImmutable $date, int $slotHours = 1): array
    {
        return $this->availability->openSlots($this->requireFacility($facilityId), $date, $slotHours);
    }

    // -- events -------------------------------------------------------------

    // Both writes go in one transaction: an event saved without its link, or a
    // link pointing at an event that failed to save, are each a broken half-state.
    public function createEvent(string $facilityId, array $validated): Event
    {
        $host     = Auth::requireLogin();
        $facility = $this->requireFacility($facilityId);

        $this->availability->assertAvailable(
            $facility,
            $validated['eventDate'],
            (string) $validated['startTime'],
            (string) $validated['endTime']
        );

        // The sport comes from the venue and never from the request. A badminton
        // hall only hosts badminton, so there is nothing for the organiser to
        // choose and nothing for anyone to tamper with.
        $validated['sport'] = $facility->getType();

        $event = $this->factory->newEvent($validated, $host, $facility);

        return Database::transaction(function () use ($event, $host): Event {
            $this->events->insert($event);
            $this->invites->insert($this->tokens->generate($event, $host->getBaseUserId()));

            return $event;
        });
    }

    public function updateEvent(string $eventId, array $validated): Event
    {
        $event = $this->requireEvent($eventId);

        EventFacilitySecurity::assertHostsEvent($event);

        $this->availability->assertAvailable(
            $event->getLocation(),
            $validated['eventDate'] ?? $event->getEventDate(),
            (string) ($validated['startTime'] ?? $event->getStartTime()),
            (string) ($validated['endTime'] ?? $event->getEndTime()),
            $eventId
        );

        $event->updateDetails($validated);
        $this->events->update($event);

        return $event;
    }

    public function publishEvent(string $eventId): Event
    {
        $event = $this->requireEvent($eventId);

        EventFacilitySecurity::assertHostsEvent($event);
        $this->publication->assertPublishable($event);

        $event->publish();
        $this->events->update($event);

        return $event;
    }

    public function cancelEvent(string $eventId): Event
    {
        $event = $this->requireEvent($eventId);

        EventFacilitySecurity::assertHostsEvent($event);

        $event->cancel();
        $this->events->update($event);

        return $event;
    }

    public function explainPublicationBlockers(string $eventId): ?string
    {
        return $this->publication->explainBlockers($this->requireEvent($eventId));
    }

    // Removes an event outright when there is nothing to keep, otherwise cancels
    // it. An abandoned draft is just clutter, but two things make the row worth
    // keeping, and they are reported separately so the organiser is told which
    // one applies:
    //
    //   JOINED - other people registered, and deleting would erase that
    //   PAID   - the venue was paid for, and the Payment and Refund rows in the
    //            Venue Booking module point at this event
    public function removeEvent(string $eventId): string
    {
        $event = $this->requireEvent($eventId);

        EventFacilitySecurity::assertHostsEvent($event);

        $counts = $this->events->countDependents($eventId);

        if ($counts['registrations'] > 0) {
            $event->cancel();
            $this->events->update($event);

            return 'CANCELLED_JOINED';
        }

        if ($counts['bookings'] > 0) {
            $event->cancel();
            $this->events->update($event);

            return 'CANCELLED_PAID';
        }

        // Invite links cascade away with the row.
        $this->events->delete($eventId);

        return 'DELETED';
    }

    // Whether the delete button can really delete, so the page can label it
    // honestly instead of promising something it cannot do.
    public function canHardDelete(string $eventId): bool
    {
        $counts = $this->events->countDependents($eventId);

        return $counts['registrations'] === 0 && $counts['bookings'] === 0;
    }

    // Whether the requested slot is free, without throwing. Used by the venue
    // step of event creation to mark venues the draft cannot actually use.
    public function slotProblem(string $facilityId, DateTimeImmutable $date, string $startTime, string $endTime): ?string
    {
        return $this->availability->check($this->requireFacility($facilityId), $date, $startTime, $endTime);
    }

    // -- invite links -------------------------------------------------------

    public function generateInviteLink(
        string $eventId,
        ?DateTimeImmutable $expiresAt = null,
        ?int $maxUses = null
    ): EventInvite {
        $event = $this->requireEvent($eventId);

        EventFacilitySecurity::assertHostsEvent($event);

        $invite = $this->tokens->generate($event, (string) Auth::id(), $expiresAt, $maxUses);
        $this->invites->insert($invite);

        return $invite;
    }

    public function listInviteLinks(string $eventId): array
    {
        $event = $this->requireEvent($eventId);

        EventFacilitySecurity::assertHostsEvent($event);

        return $this->invites->findByEvent($eventId);
    }

    public function revokeInviteLink(string $eventInviteId): void
    {
        $invite = $this->invites->find($eventInviteId);

        if (!$invite instanceof EventInvite) {
            throw new NotFoundException('That invite link does not exist.');
        }

        EventFacilitySecurity::assertHostsEvent($this->requireEvent($invite->getEventId()));

        $invite->revoke();
        $this->invites->update($invite);
    }

    // The token is spent before the event is returned, so a race cannot let two people in.
    public function redeemInvite(string $token): Event
    {
        $invite = $this->invites->findByToken($token);

        if (!$invite instanceof EventInvite) {
            throw new NotFoundException('That invite link is not valid.');
        }

        $event = $this->requireEvent($invite->getEventId());

        if (!$this->visibility->isVisibleTo($event, Auth::id(), $invite)) {
            throw new NotFoundException('That invite link is no longer valid.');
        }

        $this->invites->recordUse($invite);

        return $event;
    }

    // -- reading events -----------------------------------------------------

    public function viewEvent(string $eventId, ?string $viewerId = null): Event
    {
        $event = $this->requireEvent($eventId);

        if (!$this->visibility->isVisibleTo($event, $viewerId ?? Auth::id())) {
            // Same wording as a missing event, so this does not confirm it exists.
            throw new NotFoundException('That event does not exist, or you do not have access to it.');
        }

        return $event;
    }

    public function listVisibleEvents(?string $sport = null, int $limit = 50, ?string $viewerId = null): array
    {
        return $this->visibility->filterVisible(
            $this->events->findPublishedUpcoming($sport, $limit),
            $viewerId ?? Auth::id()
        );
    }

    public function listMyEvents(): array
    {
        return $this->events->findByHost(Auth::requireLogin()->getBaseUserId());
    }

    public function listEventsAtFacility(string $facilityId, bool $upcomingOnly = true, ?string $viewerId = null): array
    {
        return $this->visibility->filterVisible(
            $this->events->findByFacility($facilityId, $upcomingOnly),
            $viewerId ?? Auth::id()
        );
    }

    public function countParticipants(string $eventId): int
    {
        return $this->events->countParticipants($eventId);
    }

    private function requireFacility(string $facilityId): Facility
    {
        $facility = $this->facilities->find($facilityId);

        if (!$facility instanceof Facility) {
            throw new NotFoundException('That venue does not exist.');
        }

        return $facility;
    }

    private function requireEvent(string $eventId): Event
    {
        $event = $this->events->find($eventId);

        if (!$event instanceof Event) {
            throw new NotFoundException('That event does not exist.');
        }

        return $event;
    }
}
