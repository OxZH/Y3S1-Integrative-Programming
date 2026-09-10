<?php
// Browsing, mapping, recommendations and joining events. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\Discovery\FeedFilterCriteria;
use App\Domain\DiscoveryFacade;
use App\NotFoundException;
use App\Security\Auth;
use DomainException;

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

        $this->view('discovery-browse', [
            'title'       => 'Find a game',
            'events'      => $this->facade->browseEvents($criteria, $viewerId),
            'criteria'    => $criteria,
            'input'       => $_GET,
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

        try {
            $this->facade->joinEvent($eventId);
            $this->flash('success', 'You have joined this game.');
        } catch (NotFoundException $e) {
            $this->flash('error', $e->getMessage());
        } catch (DomainException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect(url('event', 'show', ['id' => $eventId]));
    }

    public function leave(): void
    {
        $this->requirePostWithCsrf();

        try {
            $this->facade->leaveEvent((string) ($_POST['eventRegistrationId'] ?? ''));
            $this->flash('success', 'You are no longer registered for this game.');
        } catch (NotFoundException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect(url('discovery', 'mine'));
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
