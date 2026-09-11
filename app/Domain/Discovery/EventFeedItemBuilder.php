<?php
// Builder pattern: step-by-step assembly of an EventFeedItem. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * The same raw data (an event, a distance, a rating, a recommendation score) is
 * assembled into two different shapes depending on where it is going: a full
 * card for the browse list, or a handful of fields for a map marker. Rather than
 * branch on "which screen is this for" inside one method, each screen gets its
 * own builder that decides, step by step, what it actually keeps.
 *
 * EventFeedDirector runs every builder through the same four steps in the same
 * order; only what each concrete builder does inside a step differs.
 */
interface EventFeedItemBuilder
{
    /** @param array<string,mixed> $event fields from the Event & Facility Management module's own API */
    public function addCoreDetails(array $event): static;

    public function addDistance(?float $distanceKm): static;

    public function addRating(?float $rating): static;

    public function addRecommendation(?float $score, ?string $reason): static;

    public function build(): EventFeedItem;
}
