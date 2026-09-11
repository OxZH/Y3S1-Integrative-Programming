<?php
// Concrete builder: the minimal payload used to plot a marker on the map. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

use RuntimeException;

/**
 * Runs through the same four steps as EventCardBuilder but keeps only what a
 * marker needs. Rating, fee and the recommendation reason are deliberately
 * dropped here rather than just left unused by the view - the point of a
 * separate builder is that the map payload never carries fields it has no
 * reason to send to the browser.
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

            // Several games at one venue sit on the same coordinates, so a pin
            // is a venue and opens with everything happening there. That makes
            // the venue's name the one thing the popup has to be titled with.
            $this->venueName = isset($facility['name']) ? (string) $facility['name'] : null;
        }

        return $this;
    }

    public function addDistance(?float $distanceKm): static
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    // A marker has no room for a star rating - step accepted, value discarded.
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
