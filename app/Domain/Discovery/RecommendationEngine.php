<?php
// Rule-based event recommendations. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Deliberately simple: two rules, both explainable in one sentence each, per the
 * module brief ("simple rules... favourite sport... how close"). Not a machine
 * learning model - the goal is a score a user could sanity-check for themselves,
 * and a reason string the UI can show next to it.
 */
final class RecommendationEngine
{
    private const SPORT_MATCH_POINTS = 50.0;
    private const MAX_DISTANCE_POINTS = 30.0;

    /** @return array{0:float,1:?string} [score, reason] */
    public function score(string $eventSport, ?string $favoriteSport, ?float $distanceKm): array
    {
        $points  = 0.0;
        $reasons = [];

        if ($favoriteSport !== null && strcasecmp($eventSport, $favoriteSport) === 0) {
            $points += self::SPORT_MATCH_POINTS;
            $reasons[] = 'matches your favourite sport';
        }

        if ($distanceKm !== null) {
            $proximityPoints = max(0.0, self::MAX_DISTANCE_POINTS - $distanceKm);

            if ($proximityPoints > 0.0) {
                $points += $proximityPoints;
                $reasons[] = sprintf('only %.1f km away', $distanceKm);
            }
        }

        return [$points, $reasons === [] ? null : ucfirst(implode(' and ', $reasons))];
    }
}
