<?php
// Builder pattern - Product. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * One event as shown on a Discovery page (card or map marker).
 * Not an entity, nothing here is saved to the database.
 * Most fields are optional because the two builders fill in different ones.
 */
final class EventFeedItem
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $name,
        public readonly string $sport,
        public readonly string $eventDate,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly ?string $venueName = null,
        public readonly ?string $city = null,
        public readonly ?string $visibility = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $distanceKm = null,
        public readonly ?int $spacesLeft = null,
        public readonly ?int $maxParticipants = null,
        public readonly ?float $feePerParticipant = null,
        public readonly ?float $rating = null,
        public readonly ?float $recommendationScore = null,
        public readonly ?string $recommendationReason = null
    ) {
    }
}
