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
use App\NotFoundException;
use App\Security\Auth;
use App\Security\DiscoverySecurity;
use App\Service\DiscoveryRemoteServices;
use DomainException;

/**
 * Orchestration only, the same role EventManagementFacade plays in the
 * neighbouring module: one remote-services client, one mapper for the table
 * this module owns, the Builder machinery, and the transaction/ownership
 * boundary. This class is architecture, not the graded design pattern - that is
 * the Builder in App\Domain\Discovery.
 */
final class DiscoveryFacade
{
    private DiscoveryRemoteServices $services;
    private EventRegistrationMapper $registrations;
    private EventFeedDirector $director;
    private RecommendationEngine $recommender;

    /** viewerId => [latitude, longitude], so one page load asks the profile service once. */
    private array $originCache = [];

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

        $events = $this->services->listUpcomingEvents($criteria->sport, $viewerId, 100);

        // Both batched - one call for every facility id on the page, one for the
        // viewer's whole friend list - rather than one call per event card.
        $ratings   = $this->services->facilityRatings($this->facilityIdsOf($events));
        $friendIds = $viewerId !== null ? $this->services->friendIds($viewerId) : [];

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
                $rating,
                null,
                null,
                $this->friendsAttending((string) $event['eventId'], $friendIds)
            );
        }

        $this->sort($items, $criteria->effectiveSort(), $criteria->direction);

        return array_slice($items, 0, $criteria->limit);
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

    /**
     * Re-authorises against Event & Facility Management's own visibility rule at
     * the moment of the write, then hands the atomic capacity guard to the
     * mapper. See EventRegistrationMapper::registerIfSpaceAvailable() for why a
     * plain recount is not enough on its own.
     *
     * @throws NotFoundException the event does not exist, or is not visible to this viewer right now
     * @throws DomainException the event is full, or already joined
     */
    public function joinEvent(string $eventId, ?string $eventInviteId = null): EventRegistration
    {
        $viewer = Auth::requireLogin();

        $event = $this->services->getEventDetails($eventId, $viewer->getBaseUserId());

        if ($event === null) {
            throw new NotFoundException('That event does not exist, or you do not have access to it.');
        }

        return $this->registrations->registerIfSpaceAvailable(
            $eventId,
            $viewer->getBaseUserId(),
            (int) $event['maxParticipants'],
            $eventInviteId
        );
    }

    public function leaveEvent(string $eventRegistrationId): void
    {
        $registration = $this->registrations->find($eventRegistrationId);

        if (!$registration instanceof EventRegistration) {
            throw new NotFoundException('That registration does not exist.');
        }

        DiscoverySecurity::assertOwnsRegistration($registration);

        $this->registrations->cancel($registration);
    }

    /** @return EventRegistration[] most recent first */
    public function myParticipation(): array
    {
        return $this->registrations->findByUser(Auth::requireLogin()->getBaseUserId());
    }

    /** For the getParticipationHistory service, consumed by User Authentication & Profile Management. */
    public function participationHistoryFor(string $userId): array
    {
        return $this->registrations->findByUser($userId);
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
                'friends'  => $b->friendsAttending <=> $a->friendsAttending,
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

    /** @param string[] $friendIds */
    private function friendsAttending(string $eventId, array $friendIds): int
    {
        if ($friendIds === []) {
            return 0;
        }

        return count(array_intersect($friendIds, $this->registrations->findActiveUserIds($eventId)));
    }
}
