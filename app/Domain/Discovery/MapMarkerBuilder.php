<?php
// Builder pattern - concrete builder for map markers. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

use RuntimeException;

/**
 * Same steps as EventCardBuilder but only keeps what a map pin needs.
 * Rating and recommendation are dropped so they never get sent to the browser.
 */
final class MapMarkerBuilder implements EventFeedItemBuilder
{
    private ?string $eventId = null;
    private ?string $name = null;
    private ?string $sport = null;
    private ?string $eventDate = null;
    private ?string $startTime = null;
    private ?string $venueName = null;
    private ?float $latitude = null;
    private ?float $longitude = null;
    private ?int $spacesLeft = null;
    private ?float $distanceKm = null;

    public function addCoreDetails(array $event): static
    {
        $this->eventId   = (string) $event['eventId'];
        $this->name      = (string) $event['name'];
        $this->sport     = (string) $event['sport'];
        $this->eventDate = (string) $event['eventDate'];
        $this->startTime = (string) $event['startTime'];
        $this->spacesLeft = isset($event['spacesLeft']) ? (int) $event['spacesLeft'] : null;

        $facility = $event['facility'] ?? null;

        if (is_array($facility)) {
            $this->latitude  = isset($facility['latitude']) ? (float) $facility['latitude'] : null;
            $this->longitude = isset($facility['longitude']) ? (float) $facility['longitude'] : null;

            // one pin per venue, so the popup is titled with the venue name
            $this->venueName = isset($facility['name']) ? (string) $facility['name'] : null;
        }

        return $this;
    }

    public function addDistance(?float $distanceKm): static
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    // not needed on the map, ignore
    public function addRating(?float $rating): static
    {
        return $this;
    }

    public function addRecommendation(?float $score, ?string $reason): static
    {
        return $this;
    }

    public function build(): EventFeedItem
    {
        if ($this->eventId === null || $this->latitude === null || $this->longitude === null) {
            throw new RuntimeException('addCoreDetails() with a located facility must run before build().');
        }

        return new EventFeedItem(
            eventId: $this->eventId,
            name: (string) $this->name,
            sport: (string) $this->sport,
            eventDate: (string) $this->eventDate,
            startTime: (string) $this->startTime,
            endTime: '',
            venueName: $this->venueName,
            latitude: $this->latitude,
            longitude: $this->longitude,
            distanceKm: $this->distanceKm,
            spacesLeft: $this->spacesLeft
        );
    }
}
