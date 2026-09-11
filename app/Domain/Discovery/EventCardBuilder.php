<?php
// Builder pattern - concrete builder for the full event card. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

use RuntimeException;

final class EventCardBuilder implements EventFeedItemBuilder
{
    private ?string $eventId = null;
    private ?string $name = null;
    private ?string $sport = null;
    private ?string $eventDate = null;
    private ?string $startTime = null;
    private ?string $endTime = null;
    private ?string $venueName = null;
    private ?string $city = null;
    private ?string $visibility = null;
    private ?float $latitude = null;
    private ?float $longitude = null;
    private ?int $spacesLeft = null;
    private ?int $maxParticipants = null;
    private ?float $feePerParticipant = null;
    private ?float $distanceKm = null;
    private ?float $rating = null;
    private ?float $recommendationScore = null;
    private ?string $recommendationReason = null;

    public function addCoreDetails(array $event): static
    {
        $this->eventId           = (string) $event['eventId'];
        $this->name              = (string) $event['name'];
        $this->sport              = (string) $event['sport'];
        $this->eventDate         = (string) $event['eventDate'];
        $this->startTime         = (string) $event['startTime'];
        $this->endTime           = (string) $event['endTime'];
        $this->spacesLeft        = isset($event['spacesLeft']) ? (int) $event['spacesLeft'] : null;
        $this->maxParticipants   = isset($event['maxParticipants']) ? (int) $event['maxParticipants'] : null;
        $this->feePerParticipant = isset($event['feePerParticipant']) ? (float) $event['feePerParticipant'] : null;
        $this->visibility        = isset($event['visibility']) ? (string) $event['visibility'] : null;

        $facility = $event['facility'] ?? null;

        if (is_array($facility)) {
            $this->venueName = isset($facility['name']) ? (string) $facility['name'] : null;
            $this->city      = isset($facility['city']) ? (string) $facility['city'] : null;
            $this->latitude  = isset($facility['latitude']) ? (float) $facility['latitude'] : null;
            $this->longitude = isset($facility['longitude']) ? (float) $facility['longitude'] : null;
        }

        return $this;
    }

    public function addDistance(?float $distanceKm): static
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    public function addRating(?float $rating): static
    {
        $this->rating = $rating;

        return $this;
    }

    public function addRecommendation(?float $score, ?string $reason): static
    {
        $this->recommendationScore  = $score;
        $this->recommendationReason = $reason;

        return $this;
    }

    public function build(): EventFeedItem
    {
        if ($this->eventId === null) {
            throw new RuntimeException('addCoreDetails() must run before build().');
        }

        return new EventFeedItem(
            eventId: $this->eventId,
            name: (string) $this->name,
            sport: (string) $this->sport,
            eventDate: (string) $this->eventDate,
            startTime: (string) $this->startTime,
            endTime: (string) $this->endTime,
            venueName: $this->venueName,
            city: $this->city,
            visibility: $this->visibility,
            latitude: $this->latitude,
            longitude: $this->longitude,
            distanceKm: $this->distanceKm,
            spacesLeft: $this->spacesLeft,
            maxParticipants: $this->maxParticipants,
            feePerParticipant: $this->feePerParticipant,
            rating: $this->rating,
            recommendationScore: $this->recommendationScore,
            recommendationReason: $this->recommendationReason
        );
    }
}
