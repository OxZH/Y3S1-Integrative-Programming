<?php
// Browsing, mapping, recommendations and joining events. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\Discovery\FeedFilterCriteria;
use App\Domain\DiscoveryService;
use App\Domain\PaymentService;
use App\NotFoundException;
use App\Security\Auth;
use DomainException;
use RuntimeException;

final class DiscoveryController extends Controller
{
    private DiscoveryService $discovery;

    public function __construct()
    {
        $this->discovery = new DiscoveryService();
    }

    public function index(): void
    {
        $criteria = FeedFilterCriteria::fromArray($_GET);
        $viewerId = Auth::id();

        // events the user already joined, so the card can show a "Joined" tag
        $joined = [];

        if ($viewerId !== null) {
            foreach ($this->discovery->myParticipation() as $registration) {
                if ($registration->isActive()) {
                    $joined[$registration->getEventId()] = true;
                }
            }
        }

        $this->view('discovery-browse', [
            'title'       => 'Find a game',
            'events'      => $this->discovery->browseEvents($criteria, $viewerId),
            'joined'      => $joined,
            'criteria'    => $criteria,
            'input'       => $_GET,
            'sports'      => $this->discovery->availableSports($viewerId),
            // used to disable the radius filter if the user has no saved location
            'hasPosition' => $this->discovery->hasKnownPosition($viewerId),
        ]);
    }

    public function map(): void
    {
        $latitude  = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
        $longitude = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float) $_GET['lng'] : null;

        $this->view('discovery-map', [
            'title'   => 'Map',
            'markers' => $this->discovery->mapMarkers(Auth::id(), $latitude, $longitude),
        ]);
    }

    public function recommended(): void
    {
        Auth::requireLogin();

        $this->view('discovery-recommended', [
            'title'  => 'Recommended for you',
            'events' => $this->discovery->recommendedEvents((string) Auth::id()),
        ]);
    }

    public function join(): void
    {
        $this->requirePostWithCsrf();

        $eventId = (string) ($_POST['eventId'] ?? '');

        if ($eventId === '') {
            $this->redirect(url('discovery'));
        }

        // joining goes through the payment checkout, the registration is only
        // written once the fee is paid (see PaymentService::takePlace)
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

            (new PaymentService())->cancelParticipantPayment(
                $eventId,
                Auth::requireLogin()->getBaseUserId()
            );
            $this->flash('success', 'Your registration was cancelled. Any eligible fee was refunded.');
        } catch (NotFoundException | DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        // go back to the event page if we came from there, otherwise My participation
        $this->redirect($eventId !== ''
            ? url('event', 'show', ['id' => $eventId])
            : url('discovery', 'mine'));
    }

    public function mine(): void
    {
        Auth::requireLogin();

        $this->view('discovery-mine', [
            'title'         => 'My participation',
            'registrations' => $this->discovery->myParticipation(),
        ]);
    }
}
