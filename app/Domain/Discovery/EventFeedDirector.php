<?php
// Builder pattern: the Director. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Owns the one thing a builder must not decide for itself: the order of
 * construction. Every feed item, card or marker, is put together the same way -
 * core details, then distance, then rating, then the recommendation signal - so
 * a card and a marker built from the same event can never drift out of sync on
 * what a "complete" item means, only on how much of it a given screen keeps.
 */
final class EventFeedDirector
{
    /** @param array<string,mixed> $event */
    public function direct(
        EventFeedItemBuilder $builder,
        array $event,
        ?float $distanceKm = null,
        ?float $rating = null,
        ?float $recommendationScore = null,
        ?string $recommendationReason = null
    ): EventFeedItem {
        return $builder
            ->addCoreDetails($event)
            ->addDistance($distanceKm)
            ->addRating($rating)
            ->addRecommendation($recommendationScore, $recommendationReason)
            ->build();
    }
}
