<?php
// Event creation, publication and invite links. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Controller;

use App\Competitiveness;
use App\Core\Controller;
use App\Domain\EventManagementFacade;
use App\EventVisibility;
use App\FitnessRequirement;
use App\Security\Auth;
use App\Security\Validator;
use App\ServiceUnavailableException;
use App\SkillLevel;
use App\ValidationException;
use DateTimeImmutable;
use DomainException;

/**
 * Creating an event is one form: the game details plus the venue picked from a
 * dropdown. Saving it writes the event as DRAFT and hands over to the Venue
 * Booking & Payment module, which returns to finalise() once the venue is paid
 * for. Only then does the event become visible to anyone else.
 */
final class EventController extends Controller
{
    private EventManagementFacade $facade;

    public function __construct()
    {
        $this->facade = new EventManagementFacade();
    }

    // -- browsing -----------------------------------------------------------

    public function index(): void
    {
        $sport = is_string($_GET['sport'] ?? null) ? $_GET['sport'] : null;

        $this->view('event-list', [
            'title'  => 'Upcoming games',
            'events' => $this->facade->listVisibleEvents($sport),
            'sport'  => $sport,
        ]);
    }

    public function mine(): void
    {
        Auth::requireLogin();

        $this->view('event-mine', [
            'title'  => 'My events',
            'events' => $this->facade->listMyEvents(),
        ]);
    }

    public function show(): void
    {
        $eventId = $this->queryId();

        if ($eventId === null) {
            $this->redirect(url('event'));
        }

        $event  = $this->facade->viewEvent($eventId);
        $isHost = Auth::id() !== null && $event->isHostedBy((string) Auth::id());

        $this->view('event-show', [
            'title'        => $event->getName(),
            'event'        => $event,
            'participants' => $this->facade->countParticipants($eventId),
            'isHost'       => $isHost,
            'blocker'      => $isHost ? $this->facade->explainPublicationBlockers($eventId) : null,
        ]);
    }

    public function invite(): void
    {
        $token = $_GET['token'] ?? '';

        if (!is_string($token) || $token === '') {
            $this->redirect(url('event'));
        }

        $event = $this->facade->redeemInvite($token);

        $this->flash('success', 'You were invited to this game.');
        $this->redirect(url('event', 'show', ['id' => $event->getEventId()]));
    }

    // -- creating -----------------------------------------------------------

    public function create(): void
    {
        Auth::requireLogin();

        // facilityId in the query pre-selects the venue, for arriving from a
        // venue page rather than picking from the dropdown.
        $this->renderForm(['facilityId' => $this->queryId('facilityId') ?? ''], []);
    }

    public function store(): void
    {
        $this->requirePostWithCsrf();
        Auth::requireLogin();

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

        // Over to the Venue Booking & Payment module. It returns to finalise().
        $this->redirect('booking.php?eventId=' . urlencode((string) $event->getEventId()));
    }

    public function finalise(): void
    {
        $eventId = $this->queryId();

        if ($eventId === null) {
            $this->redirect(url('event', 'mine'));
        }

        Auth::requireLogin();

        $this->view('event-finalise', [
            'title'   => 'Confirm your event',
            'event'   => $this->facade->viewEvent($eventId),
            'blocker' => $this->facade->explainPublicationBlockers($eventId),
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
        } catch (DomainException $e) {
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
        } catch (DomainException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect(url('event', 'show', ['id' => $eventId]));
        }

        $this->flash('success', $outcome === 'DELETED'
            ? 'The event has been deleted.'
            : 'People had already booked or joined this event, so it has been cancelled rather than deleted.');

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

    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $errors
     */
    private function renderForm(array $input, array $errors): void
    {
        $venues = $this->facade->listBookableFacilities();

        $this->view('event-form', [
            'title'   => 'Create an event',
            'input'   => $input,
            'errors'  => $errors,
            'venues'  => $venues,
            'ratings' => $this->facade->ratingsFor($venues),
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validated(array $input): array
    {
        return (new Validator($input))
            ->required('facilityId', 'Venue')->identifier('facilityId', 'Venue')
            ->required('name', 'Event name')->text('name', 'Event name', 3, 150)
            ->required('sport', 'Sport')->text('sport', 'Sport', 2, 50)
            ->required('eventDate', 'Date')->date('eventDate', 'Date', true)
            ->required('startTime', 'Start time')->time('startTime', 'Start time')
            ->required('endTime', 'End time')->time('endTime', 'End time')
            ->timeAfter('startTime', 'endTime', 'End time')
            ->required('minParticipants', 'Minimum players')->integer('minParticipants', 'Minimum players', 1, 200)
            ->required('maxParticipants', 'Maximum players')->integer('maxParticipants', 'Maximum players', 1, 200)
            ->atLeast('minParticipants', 'maxParticipants', 'Maximum players')
            ->required('skillLevel', 'Skill level')->enum('skillLevel', 'Skill level', SkillLevel::class)
            ->required('fitnessRequirement', 'Fitness level')->enum('fitnessRequirement', 'Fitness level', FitnessRequirement::class)
            ->required('competitiveness', 'Event nature')->enum('competitiveness', 'Event nature', Competitiveness::class)
            ->required('visibility', 'Visibility')->enum('visibility', 'Visibility', EventVisibility::class)
            ->decimal('feePerParticipant', 'Fee per player', 0, 9999.99)
            ->validate();
    }
}
