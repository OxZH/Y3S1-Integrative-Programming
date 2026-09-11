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
 * Main service class for the Discovery module.
 * The design pattern for this module is the Builder in App\Domain\Discovery, not this class.
 */
final class DiscoveryService
{
    private DiscoveryRemoteServices $services;
    private EventRegistrationMapper $registrations;
    private EventFeedDirector $director;
    private RecommendationEngine $recommender;

    // caches so each API is only called once per request
    private array $originCache = [];
    private array $eventDetailsCache = [];
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
        // distance is measured from the user's saved location
        $criteria = $this->resolveOrigin($criteria, $viewerId);

        $events = $this->feed($criteria->sport, $viewerId);

        // get all ratings in one call instead of one per card
        $ratings = $this->services->facilityRatings($this->facilityIdsOf($events));

        $items = [];

        foreach ($events as $event) {
            $spacesLeft = (int) ($event['spacesLeft'] ?? 0);

            if ($criteria->minSpacesLeft !== null && $spacesLeft < $criteria->minSpacesLeft) {
                continue;
            }

            $distance = $this->distanceTo($event, $criteria->latitude, $criteria->longitude);

            // no distance means we can't tell if it's nearby, so skip it when a radius is set
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
     * Sports that currently have events, for the filter dropdown.
     * @return string[]
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
    // Join/leave is not here. Joining goes through the payment checkout, and
    // PaymentService calls EventRegistrationMapper::registerIfSpaceAvailable()
    // once the fee is paid. Leaving does the refund and cancel() together.

    /** The logged-in user's active registration for this event, or null. */
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

    public const PLAYERS_PER_PAGE = 10;

    /**
     * One page of the players list. Page number is clamped, so ?players=999 just shows the last page.
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

    /** @return EventRegistration[] newest first */
    public function myParticipation(): array
    {
        return $this->registrations->findByUser(Auth::requireLogin()->getBaseUserId());
    }

    /**
     * Used by the getParticipationHistory web service (called by the User module).
     * Event details come from the Event module with the viewerId, so if the viewer
     * can no longer see that event the row comes back with available = false.
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

    /**
     * Haversine distance in km from the user to the event's venue.
     * @param array<string,mixed> $event
     */
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

    /** Get the user's saved location from their profile (once per request). */
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
     * getEventDetails with caching. Returns null instead of throwing if the Event
     * module is down, so the history page still loads.
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
     * listUpcomingEvents with caching (browse page and sport dropdown share one call).
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
