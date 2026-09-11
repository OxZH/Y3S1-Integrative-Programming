<?php
// Single entry point for the Discovery & Event Matchmaking module. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Discovery\EventCardBuilder;
use App\Domain\Discovery\EventFeedDirector;
use App\Domain\Discovery\EventFeedItem;
use App\Domain\Discovery\FeedFilterCriteria;
use App\Domain\Discovery\MapMarkerBuilder;
use App\Domain\Discovery\RecommendationEngine;
use App\Model\EventRegistration;
use App\Model\EventRegistrationMapper;
use App\Security\Auth;
use App\Service\DiscoveryRemoteServices;
use App\ServiceUnavailableException;

/**
 * Orchestration only, the same role EventManagementFacade plays in the
 * neighbouring module: one remote-services client, one mapper for the table
 * this module owns, the Builder machinery, and the transaction/ownership
 * boundary. This class is architecture, not the graded design pattern - that is
 * the Builder in App\Domain\Discovery.
 */
final class DiscoveryService
{
    private DiscoveryRemoteServices $services;
    private EventRegistrationMapper $registrations;
    private EventFeedDirector $director;
    private RecommendationEngine $recommender;

    /** viewerId => [latitude, longitude], so one page load asks the profile service once. */
    private array $originCache = [];

    /** "eventId|viewerId" => details or null, so a repeated event is asked about once. */
    private array $eventDetailsCache = [];

    /** "sport|viewerId" => the feed, so the browse page and its sport list share one call. */
    private array $feedCache = [];

    public function __construct(
        ?DiscoveryRemoteServices $services = null,
        ?EventRegistrationMapper $registrations = null
    ) {
        $this->services      = $services ?? new DiscoveryRemoteServices();
        $this->registrations = $registrations ?? new EventRegistrationMapper();
        $this->director      = new EventFeedDirector();
        $this->recommender   = new RecommendationEngine();
    }

    // -- browsing -------------------------------------------------------------

    /** @return EventFeedItem[] */
    public function browseEvents(FeedFilterCriteria $criteria, ?string $viewerId): array
    {
        // "Within 10 km" needs somewhere to measure from, and that is the
        // player's own saved position - never something they have to type.
        $criteria = $this->resolveOrigin($criteria, $viewerId);

        $events = $this->feed($criteria->sport, $viewerId);

        // Batched - one call for every facility id on the page, rather than one
        // call per event card.
        $ratings = $this->services->facilityRatings($this->facilityIdsOf($events));

        $items = [];

        foreach ($events as $event) {
            $spacesLeft = (int) ($event['spacesLeft'] ?? 0);

            if ($criteria->minSpacesLeft !== null && $spacesLeft < $criteria->minSpacesLeft) {
                continue;
            }

            $distance = $this->distanceTo($event, $criteria->latitude, $criteria->longitude);

            // An event with no distance is out of range by definition once a
            // radius was asked for - there is no evidence it is near.
            if ($criteria->radiusKm !== null && ($distance === null || $distance > $criteria->radiusKm)) {
                continue;
            }

            $facilityId = $event['facility']['facilityId'] ?? null;
            $rating     = is_string($facilityId) ? ($ratings[$facilityId] ?? null) : null;

            $items[] = $this->director->direct(
                new EventCardBuilder(),
                $event,
                $distance,
                $rating
            );
        }

        $this->sort($items, $criteria->effectiveSort(), $criteria->direction);

        return array_slice($items, 0, $criteria->limit);
    }

    /**
     * The sports actually on offer right now, for the browse page's filter.
     *
     * Taken from the events themselves rather than from the Sport enum, so the
     * list never offers a sport that would return nothing. It shrinks and grows
     * with what organisers have published, which is the point.
     *
     * @return string[] distinct, alphabetical
     */
    public function availableSports(?string $viewerId): array
    {
        $sports = [];

        foreach ($this->feed(null, $viewerId) as $event) {
            if (is_string($event['sport'] ?? null) && $event['sport'] !== '') {
                $sports[$event['sport']] = true;
            }
        }

        $sports = array_keys($sports);
        sort($sports);

        return $sports;
    }

    /** Whether this viewer has a position on file at all, so the page can say so. */
    public function hasKnownPosition(?string $viewerId): bool
    {
        return $this->resolveOrigin(new FeedFilterCriteria(), $viewerId)->hasOrigin();
    }

    /** @return EventFeedItem[] */
    public function mapMarkers(?string $viewerId, ?float $latitude = null, ?float $longitude = null): array
    {
        $events  = $this->services->listUpcomingEvents(null, $viewerId, 100);
        $markers = [];

        foreach ($events as $event) {
            if (!is_array($event['facility'] ?? null)) {
                continue;
            }

            $markers[] = $this->director->direct(
                new MapMarkerBuilder(),
                $event,
                $this->distanceTo($event, $latitude, $longitude)
            );
        }

        return $markers;
    }

    /** @return EventFeedItem[] */
    public function recommendedEvents(string $viewerId, int $limit = 10): array
    {
        $profile = $this->services->userProfile($viewerId);
        $events  = $this->services->listUpcomingEvents(null, $viewerId, 100);
        $items   = [];

        foreach ($events as $event) {
            $distance = $this->distanceTo(
                $event,
                $profile['latitude'] ?? null,
                $profile['longitude'] ?? null
            );

            [$score, $reason] = $this->recommender->score(
                (string) $event['sport'],
                $profile['favoriteSports'] ?? null,
                $distance
            );

            if ($score <= 0.0) {
                continue;
            }

            $items[] = $this->director->direct(
                new EventCardBuilder(),
                $event,
                $distance,
                null,
                $score,
                $reason
            );
        }

        usort($items, static fn (EventFeedItem $a, EventFeedItem $b): int
            => ($b->recommendationScore ?? 0.0) <=> ($a->recommendationScore ?? 0.0));

        return array_slice($items, 0, $limit);
    }

    // -- participation --------------------------------------------------------
    //
    // Joining and leaving are not methods here. Every join goes through Venue
    // Booking & Payment's checkout, and the place is taken at the moment the
    // fee is paid - PaymentService::takePlace() calls this module's
    // EventRegistrationMapper::registerIfSpaceAvailable() inside the payment
    // transaction, so the row lock that keeps a full game full is held until
    // the payment row is written too. Leaving is the same in reverse: the
    // refund and EventRegistrationMapper::cancel() land together. This module
    // still owns the table and the guard; it is simply not the entry point.

    /**
     * The signed-in player's live registration for one event, or null.
     *
     * Event & Facility Management's own detail page asks this so its button can
     * read "Join" or "Leave" rather than finding out on submit. Joining belongs
     * to this module, so the answer comes from here.
     */
    public function myRegistrationFor(string $eventId): ?EventRegistration
    {
        $viewerId = Auth::id();

        if ($viewerId === null) {
            return null;
        }

        $registration = $this->registrations->findForUserAndEvent($viewerId, $eventId);

        return $registration instanceof EventRegistration && $registration->isActive()
            ? $registration
            : null;
    }

    /** How many players an event's team sheet shows before it needs a second page. */
    public const PLAYERS_PER_PAGE = 10;

    /**
     * One page of an event's team sheet.
     *
     * Who is in a game is this module's to answer - the registrations are its
     * table - so Event & Facility Management's detail page asks rather than
     * counting rows itself. The page number is clamped to something that exists,
     * so ?players=999 lands on the last page instead of an empty card.
     *
     * @return array{players:EventRegistration[],total:int,page:int,pages:int}
     */
    public function playersFor(string $eventId, int $page = 1): array
    {
        $total = $this->registrations->countActive($eventId);
        $pages = max(1, (int) ceil($total / self::PLAYERS_PER_PAGE));
        $page  = max(1, min($pages, $page));

        return [
            'players' => $this->registrations->findActiveForEvent(
                $eventId,
                self::PLAYERS_PER_PAGE,
                ($page - 1) * self::PLAYERS_PER_PAGE
            ),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
        ];
    }

    /** @return EventRegistration[] most recent first */
    public function myParticipation(): array
    {
        return $this->registrations->findByUser(Auth::requireLogin()->getBaseUserId());
    }

    /**
     * For the getParticipationHistory service, consumed by User Authentication &
     * Profile Management.
     *
     * The registration rows are this module's own. What each event actually *is*
     * belongs to Event & Facility Management, so it is asked - once per event,
     * with the viewer attached - rather than read out of its tables. A refusal
     * means "this person may no longer see that event": the row still appears,
     * because they really did join it, but it is marked unavailable and carries
     * no details.
     *
     * That visibility check lives here, not in the consuming module, because
     * these rows are this module's to explain. A consumer gets one call and a
     * straight answer instead of a lookup per row.
     *
     * @return array<int,array{eventId:string,eventName:?string,sport:?string,
     *         eventDate:?string,status:string,registerTime:string,available:bool}>
     */
    public function participationHistoryFor(string $userId, ?string $viewerId = null, int $limit = 10): array
    {
        $registrations = array_slice(
            $this->registrations->findByUser($userId),
            0,
            max(1, min(50, $limit))
        );

        return array_map(
            fn (EventRegistration $registration): array => $this->describe($registration, $viewerId),
            $registrations
        );
    }

    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $event */
    private function distanceTo(array $event, ?float $latitude, ?float $longitude): ?float
    {
        $facility = $event['facility'] ?? null;

        if ($latitude === null || $longitude === null || !is_array($facility)) {
            return null;
        }

        $facilityLat = (float) ($facility['latitude'] ?? 0);
        $facilityLng = (float) ($facility['longitude'] ?? 0);

        $deltaLat = deg2rad($facilityLat - $latitude);
        $deltaLng = deg2rad($facilityLng - $longitude);

        $a = sin($deltaLat / 2) ** 2
           + cos(deg2rad($latitude)) * cos(deg2rad($facilityLat)) * sin($deltaLng / 2) ** 2;

        return round(6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /** @param EventFeedItem[] $items */
    private function sort(array &$items, string $sortBy, string $direction): void
    {
        $factor = strtoupper($direction) === 'DESC' ? -1 : 1;

        usort($items, static function (EventFeedItem $a, EventFeedItem $b) use ($sortBy, $factor): int {
            return $factor * match ($sortBy) {
                'distance' => ($a->distanceKm ?? PHP_FLOAT_MAX) <=> ($b->distanceKm ?? PHP_FLOAT_MAX),
                'rating'   => ($b->rating ?? 0.0) <=> ($a->rating ?? 0.0),
                default    => strcmp($a->eventDate . $a->startTime, $b->eventDate . $b->startTime),
            };
        });
    }

    /**
     * Fills in where "within N km" is measured from: the viewer's own saved
     * position, fetched once per request. An anonymous visitor, or an account
     * whose address could not be placed, simply has no origin - the radius and
     * the distance ordering then do not apply, rather than the page failing.
     */
    private function resolveOrigin(FeedFilterCriteria $criteria, ?string $viewerId): FeedFilterCriteria
    {
        if ($viewerId === null || $criteria->hasOrigin()) {
            return $criteria;
        }

        if (!array_key_exists($viewerId, $this->originCache)) {
            $profile = $this->services->userProfile($viewerId);

            $this->originCache[$viewerId] = [
                $profile['latitude'] ?? null,
                $profile['longitude'] ?? null,
            ];
        }

        [$latitude, $longitude] = $this->originCache[$viewerId];

        return $criteria->withOrigin($latitude, $longitude);
    }

    /**
     * One history row: what this module knows for certain, plus whatever Event &
     * Facility Management is willing to tell this viewer about the event.
     *
     * @return array{eventId:string,eventName:?string,sport:?string,eventDate:?string,
     *         status:string,registerTime:string,available:bool}
     */
    private function describe(EventRegistration $registration, ?string $viewerId): array
    {
        $row = [
            'eventId'      => $registration->getEventId(),
            'eventName'    => null,
            'sport'        => null,
            'eventDate'    => null,
            'status'       => $registration->getStatus()->value,
            'registerTime' => $registration->getRegisterTime()->format('Y-m-d H:i:s'),
            'available'    => false,
        ];

        $event = $this->eventDetails($registration->getEventId(), $viewerId);

        if ($event === null) {
            return $row;
        }

        return array_replace($row, [
            'eventName' => is_scalar($event['name'] ?? null) ? (string) $event['name'] : null,
            'sport'     => is_scalar($event['sport'] ?? null) ? (string) $event['sport'] : null,
            'eventDate' => is_scalar($event['eventDate'] ?? null) ? (string) $event['eventDate'] : null,
            'available' => true,
        ]);
    }

    /**
     * getEventDetails with the history's own failure rule. Joining fails closed
     * when Event & Facility Management is unreachable, because that is a
     * security decision; a history is a display, so an outage costs the row its
     * details rather than emptying somebody's profile page.
     *
     * @return array<string,mixed>|null
     */
    private function eventDetails(string $eventId, ?string $viewerId): ?array
    {
        $key = $eventId . '|' . ($viewerId ?? '');

        if (!array_key_exists($key, $this->eventDetailsCache)) {
            try {
                $this->eventDetailsCache[$key] = $this->services->getEventDetails($eventId, $viewerId);
            } catch (ServiceUnavailableException $e) {
                error_log('Participation history: ' . $e->getMessage());

                $this->eventDetailsCache[$key] = null;
            }
        }

        return $this->eventDetailsCache[$key];
    }

    /**
     * listUpcomingEvents, remembered for the length of the request. Browsing and
     * building the sport filter both want the feed, and with no sport chosen
     * they want the same one - so the page asks Event & Facility Management
     * once, not twice.
     *
     * @return array<int,array<string,mixed>>
     */
    private function feed(?string $sport, ?string $viewerId): array
    {
        $key = ($sport ?? '') . '|' . ($viewerId ?? '');

        if (!array_key_exists($key, $this->feedCache)) {
            $this->feedCache[$key] = $this->services->listUpcomingEvents($sport, $viewerId, 100);
        }

        return $this->feedCache[$key];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return string[]
     */
    private function facilityIdsOf(array $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            $facilityId = $event['facility']['facilityId'] ?? null;

            if (is_string($facilityId)) {
                $ids[] = $facilityId;
            }
        }

        return $ids;
    }
}
