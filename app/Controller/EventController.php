<?php
// Event creation, publication and invite links. Author: Goh Jian Yu

namespace App\Controller;

use App\Competitiveness;
use App\Core\Controller;
use App\Domain\DiscoveryService; // js part
use App\Domain\EventManagementFacade;
use App\Domain\InviteTokens;
use App\EventVisibility;
use App\FitnessRequirement;
use App\NotFoundException;
use App\Security\Auth;
use App\Security\EventFacilitySecurity;
use App\Security\Validator;
use App\ServiceUnavailableException;
use App\SkillLevel;
use App\ValidationException;
use DateTimeImmutable;
use DomainException;

// Creating an event is one form: the game details plus the venue picked from a
// dropdown. Saving it writes the event as DRAFT and hands over to the Venue
// Booking & Payment module, which returns to finalise() once the venue is paid
// for. Only then does the event become visible to anyone else.
final class EventController extends Controller
{
    // How far ahead an event may be scheduled. The form and the validator both
    // read this, so the date the browser offers is the date the server accepts.
    const BOOKING_WINDOW = '+3 months';

    private EventManagementFacade $facade;

    public function __construct()
    {
        $this->facade = new EventManagementFacade();
    }

    // -- browsing -----------------------------------------------------------

    // js part - index() rendered the temporary Upcoming games listing and has
    // been removed with it. Browsing lives on Find a game, which reaches the
    // same events through listVisibleEvents on this module's web service rather
    // than through a second page. The facade method itself is untouched, since
    // api/event.php still serves it.

    public function mine(): void
    {
        EventFacilitySecurity::assertCanHostEvents();

        $this->view('event-mine', [
            'title'  => 'My events',
            'events' => $this->facade->listMyEvents(),
        ]);
    }

    public function show(): void
    {
        $eventId = $this->queryId();

        if ($eventId === null) {
            $this->redirect(url('discovery')); // js part - was url('event')
        }

        $event  = $this->facade->viewEvent($eventId);
        $isHost = Auth::id() !== null && $event->isHostedBy((string) Auth::id());

        $discovery = new DiscoveryService(); // js part

        $this->view('event-show', [
            'title'        => $event->getName(),
            'event'        => $event,
            'participants' => $this->facade->countParticipants($eventId),
            'isHost'       => $isHost,
            'blocker'      => $isHost ? $this->facade->explainPublicationBlockers($eventId) : null,
            'canDelete'    => $isHost && $this->facade->canHardDelete($eventId),

            // js part - joining an event belongs to Discovery & Event
            // Matchmaking, so that module is asked whether this viewer is
            // already in, and who else is. Null covers "not joined" and "not
            // signed in" alike, which is all the view needs to pick its button.
            'myRegistration' => $discovery->myRegistrationFor($eventId),
            'playerPage'     => $discovery->playersFor(
                $eventId,
                (int) ($_GET['players'] ?? 1)
            ),
        ]);
    }

    public function invite(): void
    {
        $token = $_GET['token'] ?? '';

        if (!is_string($token) || $token === '') {
            $this->redirect(url('discovery')); // js part - was url('event')
        }

        $event = $this->facade->redeemInvite($token);

        $this->flash('success', 'You were invited to this game.');
        $this->redirect(url('event', 'show', ['id' => $event->getEventId()]));
    }

    // The same thing for somebody who copied a link rather than clicking one.
    // Find a game carries a box to paste it into, and that arrives here.
    //
    // A POST, unlike invite() above. Clicking a link in a message can only ever
    // be a GET, but a form on our own page has no such excuse, and redeeming
    // spends a use of the token.
    public function redeem(): void
    {
        $this->requirePostWithCsrf();

        $pasted = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $token  = InviteTokens::fromPastedLink($pasted);

        if ($token === null) {
            $this->flash('error', 'That does not look like an invite link. Paste the whole link you '
                                . 'were sent, or just the code at the end of it.');
            $this->redirect(url('discovery'));
        }

        try {
            $event = $this->facade->redeemInvite($token);
        } catch (NotFoundException $e) {
            // Expired, revoked, used up or never real - all reported the same,
            // so a wrong guess learns nothing about which tokens exist.
            $this->flash('error', $e->getMessage());
            $this->redirect(url('discovery'));
        }

        $this->flash('success', 'That invite works. You can join the game from this page.');
        $this->redirect(url('event', 'show', ['id' => $event->getEventId()]));
    }

    // -- creating -----------------------------------------------------------

    public function create(): void
    {
        EventFacilitySecurity::assertCanHostEvents();

        // facilityId in the query pre-selects the venue, for arriving from a
        // venue page rather than picking from the dropdown.
        $this->renderForm(['facilityId' => $this->queryId('facilityId') ?? ''], []);
    }

    public function store(): void
    {
        $this->requirePostWithCsrf();
        EventFacilitySecurity::assertCanHostEvents();

        try {
            $clean      = $this->validated($_POST);
            $facilityId = (string) $clean['facilityId'];

            $event = $this->facade->createEvent($facilityId, $clean);
        } catch (ValidationException $e) {
            $this->renderForm($_POST, $e->getErrors());

            return;
        } catch (DomainException $e) {
            // The venue is closed then, or already taken. It is the venue choice
            // that has to change, so the message belongs on that field.
            $this->renderForm($_POST, ['facilityId' => $e->getMessage()]);

            return;
        }

        $this->redirect(
            'payment.php?action=venue&eventId=' . urlencode((string) $event->getEventId())
        );
    }

    public function finalise(): void
    {
        $eventId = $this->queryId();

        if ($eventId === null) {
            $this->redirect(url('event', 'mine'));
        }

        EventFacilitySecurity::assertCanHostEvents();

        $event = $this->facade->viewEvent($eventId);
        EventFacilitySecurity::assertHostsEvent($event);

        $this->view('event-finalise', [
            'title'   => 'Confirm your event',
            'event'     => $event,
            'blocker'   => $this->facade->explainPublicationBlockers($eventId),
            'canDelete' => $this->facade->canHardDelete($eventId),
        ]);
    }

    public function publish(): void
    {
        $this->requirePostWithCsrf();

        $eventId = (string) ($_POST['eventId'] ?? '');

        try {
            $this->facade->publishEvent($eventId);
            $this->flash('success', 'Your event is live and other players can now find it.');
        } catch (DomainException | ServiceUnavailableException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect(url('event', 'show', ['id' => $eventId]));
    }

    // -- lifecycle ----------------------------------------------------------

    public function cancel(): void
    {
        $this->requirePostWithCsrf();

        $eventId = (string) ($_POST['eventId'] ?? '');

        try {
            $this->facade->cancelEvent($eventId);
            $this->flash('success', 'The event has been cancelled.');
        } catch (DomainException | ServiceUnavailableException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect(url('event', 'show', ['id' => $eventId]));
    }

    public function complete(): void
    {
        $this->requirePostWithCsrf();
        $eventId = (string) ($_POST['eventId'] ?? '');

        try {
            $this->facade->completeEvent($eventId);
            $this->flash('success', 'The event is completed and participant fees were internally settled.');
        } catch (DomainException | ServiceUnavailableException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect(url('event', 'show', ['id' => $eventId]));
    }

    public function delete(): void
    {
        $this->requirePostWithCsrf();

        $eventId = (string) ($_POST['eventId'] ?? '');

        try {
            $outcome = $this->facade->removeEvent($eventId);
        } catch (DomainException | ServiceUnavailableException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect(url('event', 'show', ['id' => $eventId]));
        }

        if ($outcome === 'DELETED') {
            $this->flash('success', 'The event has been deleted.');
        } else if ($outcome === 'CANCELLED_JOINED') {
            $this->flash('success', 'Other players had already joined, so the event has been cancelled '
                                  . 'rather than deleted and they can see that it is off.');
        } else {
            $this->flash('success', 'The venue for this event has been paid for, so the record is kept '
                                  . 'for the payment history. The event has been cancelled instead.');
        }

        $this->redirect(url('event', 'mine'));
    }

    // -- invite links -------------------------------------------------------

    public function invites(): void
    {
        $eventId = $this->queryId();

        if ($eventId === null) {
            $this->redirect(url('event', 'mine'));
        }

        $this->view('event-invites', [
            'title'   => 'Invite links',
            'event'   => $this->facade->viewEvent($eventId),
            'invites' => $this->facade->listInviteLinks($eventId),
        ]);
    }

    public function createInvite(): void
    {
        $this->requirePostWithCsrf();

        $eventId = (string) ($_POST['eventId'] ?? '');

        $clean = (new Validator($_POST))
            ->integer('maxUses', 'Maximum uses', 1, 1000)
            ->date('expiresOn', 'Expiry date')
            ->validate();

        $expiresAt = isset($clean['expiresOn']) && $clean['expiresOn'] instanceof DateTimeImmutable
            ? $clean['expiresOn']->setTime(23, 59, 59)
            : null;

        $this->facade->generateInviteLink($eventId, $expiresAt, $clean['maxUses'] ?? null);

        $this->flash('success', 'A new invite link is ready to share.');
        $this->redirect(url('event', 'invites', ['id' => $eventId]));
    }

    public function revokeInvite(): void
    {
        $this->requirePostWithCsrf();

        $this->facade->revokeInviteLink((string) ($_POST['eventInviteId'] ?? ''));

        $this->flash('success', 'That link no longer works.');
        $this->redirect(url('event', 'invites', ['id' => (string) ($_POST['eventId'] ?? '')]));
    }

    // -----------------------------------------------------------------------

    private function renderForm(array $input, array $errors): void
    {
        $venues    = $this->facade->listBookableFacilities();
        $windowEnd = (new DateTimeImmutable('today ' . self::BOOKING_WINDOW))->format('Y-m-d');

        $this->view('event-form', [
            'title'   => 'Create an event',
            'input'   => $input,
            'errors'  => $errors,
            'venues'  => $venues,
            'ratings' => $this->facade->ratingsFor($venues),
            'maxDate' => $windowEnd,

            // Slots already taken, so the time dropdowns can grey them out
            // without asking the server again every time the date changes.
            'busy'    => $this->facade->busySlots($windowEnd),
        ]);
    }

    private function validated(array $input): array
    {
        $clean = (new Validator($input))
            ->required('facilityId', 'Venue')->identifier('facilityId', 'Venue')
            ->required('name', 'Event name')->text('name', 'Event name', 3, 150)
                ->hasLetter('name', 'Event name')

            // Far-future dates are almost always a typo, and a venue cannot
            // sensibly be held for years, so bookings stop three months out.
            ->required('eventDate', 'Date')->date('eventDate', 'Date', true, self::BOOKING_WINDOW)

            ->required('startTime', 'Start time')->time('startTime', 'Start time', true, true)
            ->required('endTime', 'End time')->time('endTime', 'End time', false, true)
            ->timeAfter('startTime', 'endTime', 'End time')
            ->notInThePast('eventDate', 'startTime', 'That start time')
            ->required('minParticipants', 'Minimum players')->integer('minParticipants', 'Minimum players', 1, 200)
            ->required('maxParticipants', 'Maximum players')->integer('maxParticipants', 'Maximum players', 1, 200)
            ->atLeast('minParticipants', 'maxParticipants', 'Maximum players')
            ->required('skillLevel', 'Skill level')->enum('skillLevel', 'Skill level', SkillLevel::class)
            ->required('fitnessRequirement', 'Fitness level')->enum('fitnessRequirement', 'Fitness level', FitnessRequirement::class)
            ->required('competitiveness', 'Event nature')->enum('competitiveness', 'Event nature', Competitiveness::class)
            ->required('visibility', 'Visibility')->enum('visibility', 'Visibility', EventVisibility::class)
            ->decimal('feePerParticipant', 'Fee per player', 0, 9999.99)
            ->validate();

        // The sport is deliberately absent from the rules above. It is not asked
        // for and not read from the request, because it belongs to the venue.
        // createEvent() fills it in from the chosen facility, which means a
        // tampered sport field has nothing to tamper with.

        return $clean;
    }
}
