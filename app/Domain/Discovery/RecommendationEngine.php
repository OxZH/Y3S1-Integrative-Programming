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

    /**
     * A player may list several favourite sports, so the event scores if it
     * matches any one of them. Matching two is not worth more than matching
     * one - an event only has one sport.
     *
     * @param string[]|string|null $favoriteSports one sport, a list, or nothing
     * @return array{0:float,1:?string} [score, reason]
     */
    public function score(string $eventSport, array|string|null $favoriteSports, ?float $distanceKm): array
    {
        $points  = 0.0;
        $reasons = [];

        $favourites = match (true) {
            is_array($favoriteSports)  => $favoriteSports,
            is_string($favoriteSports) => [$favoriteSports],
            default                    => [],
        };

        foreach ($favourites as $favourite) {
            if (is_string($favourite) && strcasecmp($eventSport, $favourite) === 0) {
                $points += self::SPORT_MATCH_POINTS;
                $reasons[] = 'matches your favourite sport';
                break;
            }
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
