<?php
// Builder pattern - Director. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Runs the build steps in a fixed order: core details, distance, rating, recommendation.
 * Works with any builder that implements EventFeedItemBuilder.
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
