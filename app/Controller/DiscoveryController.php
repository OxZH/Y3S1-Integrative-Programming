<?php
// Browsing, mapping, recommendations and joining events. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\Discovery\FeedFilterCriteria;
use App\Domain\DiscoveryFacade;
use App\Domain\PaymentFacade;
use App\NotFoundException;
use App\Security\Auth;
use DomainException;
use RuntimeException;

final class DiscoveryController extends Controller
{
    private DiscoveryFacade $facade;

    public function __construct()
    {
        $this->facade = new DiscoveryFacade();
    }

    public function index(): void
    {
        $criteria = FeedFilterCriteria::fromArray($_GET);
        $viewerId = Auth::id();

        // Which of these the viewer is already in, so a card can say so instead
        // of inviting them to join something twice. A cancelled registration
        // does not count, which is what isActive() is for.
        $joined = [];

        if ($viewerId !== null) {
            foreach ($this->facade->myParticipation() as $registration) {
                if ($registration->isActive()) {
                    $joined[$registration->getEventId()] = true;
                }
            }
        }

        $this->view('discovery-browse', [
            'title'       => 'Find a game',
            'events'      => $this->facade->browseEvents($criteria, $viewerId),
            'joined'      => $joined,
            'criteria'    => $criteria,
            'input'       => $_GET,
            // The filter offers the sports that actually have games, so it can
            // never be set to something that returns an empty page.
            'sports'      => $this->facade->availableSports($viewerId),
            // Distance filtering measures from the player's own saved position,
            // so the form says as much when there is not one to measure from.
            'hasPosition' => $this->facade->hasKnownPosition($viewerId),
        ]);
    }

    public function map(): void
    {
        $latitude  = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
        $longitude = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float) $_GET['lng'] : null;

        $this->view('discovery-map', [
            'title'   => 'Map',
            'markers' => $this->facade->mapMarkers(Auth::id(), $latitude, $longitude),
        ]);
    }

    public function recommended(): void
    {
        Auth::requireLogin();

        $this->view('discovery-recommended', [
            'title'  => 'Recommended for you',
            'events' => $this->facade->recommendedEvents((string) Auth::id()),
        ]);
    }

    public function join(): void
    {
        $this->requirePostWithCsrf();

        $eventId = (string) ($_POST['eventId'] ?? '');

        if ($eventId === '') {
            $this->redirect(url('discovery'));
        }

        // Every join goes through checkout. The place is taken there, at the
        // moment the fee is paid (or at once, for a free game), by this module's
        // own registration guard - see PaymentFacade::takePlace(). Nothing is
        // written before that, so a checkout page left open holds no seat.
        $this->redirect(
            'payment.php?action=participant&eventId=' . rawurlencode($eventId)
        );
    }

    public function leave(): void
    {
        $this->requirePostWithCsrf();
        $eventId = (string) ($_POST['eventId'] ?? '');

        try {
            if ($eventId === '') {
                throw new NotFoundException('That event is no longer available.');
            }

            (new PaymentFacade())->cancelParticipantPayment(
                $eventId,
                Auth::requireLogin()->getBaseUserId()
            );
            $this->flash('success', 'Your registration was cancelled. Any eligible fee was refunded.');
        } catch (NotFoundException | DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        // Leaving from an event's own page returns to it, so the button that was
        // pressed is still on screen; from the participation list it returns
        // there. Only an id travels, and it is only ever put back into a URL.
        $this->redirect($eventId !== ''
            ? url('event', 'show', ['id' => $eventId])
            : url('discovery', 'mine'));
    }

    public function mine(): void
    {
        Auth::requireLogin();

        $this->view('discovery-mine', [
            'title'         => 'My participation',
            'registrations' => $this->facade->myParticipation(),
        ]);
    }
}
