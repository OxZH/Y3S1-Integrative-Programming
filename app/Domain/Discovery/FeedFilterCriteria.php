<?php
// Browse filters, applied client-of-the-API-side. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Event & Facility Management's own listUpcomingEvents already filters by
 * sport. Everything else here (minimum spaces left, a radius, sort by
 * distance/rating/friends) has no equivalent on that endpoint, so it is applied
 * to the array this module gets back, not as SQL - there is no local table to
 * put an ORDER BY against.
 *
 * The origin the radius measures from is NOT taken from the query string. A
 * player types "within 10 km", not a pair of coordinates; the coordinates come
 * from their own profile, and the facade fills them in with withOrigin() once
 * it knows who is asking. Anonymous visitors have no origin, so distance
 * filtering and sorting simply do not apply to them.
 */
final class FeedFilterCriteria
{
    public const SORTS = ['date', 'distance', 'rating', 'friends'];

    public function __construct(
        public readonly ?string $sport = null,
        public readonly ?int $minSpacesLeft = null,
        public readonly ?float $radiusKm = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly string $sortBy = 'date',
        public readonly string $direction = 'ASC',
        public readonly int $limit = 50
    ) {
    }

    /** @param array<string,mixed> $input usually $_GET */
    public static function fromArray(array $input): self
    {
        $sortBy = is_string($input['sort'] ?? null) ? $input['sort'] : 'date';

        if (!in_array($sortBy, self::SORTS, true)) {
            $sortBy = 'date';
        }

        $radiusKm = self::toFloat($input['radius'] ?? null);

        return new self(
            sport: self::toText($input['sport'] ?? null),
            minSpacesLeft: self::toInt($input['minSpacesLeft'] ?? null),
            radiusKm: $radiusKm !== null ? min(500.0, max(1.0, $radiusKm)) : null,
            sortBy: $sortBy,
            direction: strtoupper((string) ($input['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC',
            limit: min(100, max(1, (int) ($input['limit'] ?? 50)))
        );
    }

    /** The same filters, measured from this point. Readonly, so it returns a copy. */
    public function withOrigin(?float $latitude, ?float $longitude): self
    {
        return new self(
            sport: $this->sport,
            minSpacesLeft: $this->minSpacesLeft,
            radiusKm: $this->radiusKm,
            latitude: $latitude,
            longitude: $longitude,
            sortBy: $this->sortBy,
            direction: $this->direction,
            limit: $this->limit
        );
    }

    public function hasOrigin(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Distance is only a real ordering once there is somewhere to measure from. */
    public function effectiveSort(): string
    {
        return ($this->sortBy === 'distance' && !$this->hasOrigin()) ? 'date' : $this->sortBy;
    }

    private static function toText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function toFloat(mixed $value): ?float
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (float) $value;
    }

    private static function toInt(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }
}
