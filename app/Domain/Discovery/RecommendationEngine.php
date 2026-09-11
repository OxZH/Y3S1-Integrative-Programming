<?php
// Rule-based event recommendations. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Simple scoring: +50 if the sport is one of the user's favourites,
 * +up to 30 depending on how close the venue is. Also returns a reason to show on the card.
 */
final class RecommendationEngine
{
    private const SPORT_MATCH_POINTS = 50.0;
    private const MAX_DISTANCE_POINTS = 30.0;

    /**
     * @param string[]|string|null $favoriteSports
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
