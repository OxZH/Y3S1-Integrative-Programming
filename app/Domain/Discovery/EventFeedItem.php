<?php
// The Builder's product. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * One event as shown on a Discovery screen. Deliberately not an Entity: nothing
 * here is written back to a table, it is assembled fresh on every request from
 * whatever combination of the event's own data, a distance, a rating and a
 * recommendation reason happens to be available.
 *
 * Every field is optional because the two builders in this namespace fill in
 * different subsets of them from the same construction steps - see
 * EventFeedItemBuilder.
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
        /** Raw EventVisibility value. The card turns it into a tag; a map marker never carries it. */
        public readonly ?string $visibility = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $distanceKm = null,
        public readonly ?int $spacesLeft = null,
        public readonly ?int $maxParticipants = null,
        public readonly ?float $feePerParticipant = null,
        public readonly ?float $rating = null,
        public readonly ?float $recommendationScore = null,
        public readonly ?string $recommendationReason = null,
        public readonly int $friendsAttending = 0
    ) {
    }
}
