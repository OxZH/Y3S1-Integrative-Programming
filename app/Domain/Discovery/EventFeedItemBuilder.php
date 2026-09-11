<?php
// Builder pattern - Builder interface. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Steps to build an EventFeedItem. EventCardBuilder keeps everything,
 * MapMarkerBuilder only keeps what the map needs. The director calls the
 * steps in the same order for both.
 */
interface EventFeedItemBuilder
{
    /** @param array<string,mixed> $event one event from the Event module's API */
    public function addCoreDetails(array $event): static;

    public function addDistance(?float $distanceKm): static;

    public function addRating(?float $rating): static;

    public function addRecommendation(?float $score, ?string $reason): static;

    public function build(): EventFeedItem;
}
