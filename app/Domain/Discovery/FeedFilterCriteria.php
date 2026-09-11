<?php
// Filters for the Find a game page. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Sport is filtered by the Event module's API. The rest (min spots, radius, sort)
 * is applied here on the returned array, since there is no local events table.
 * The user's coordinates are not taken from the URL, DiscoveryService fills them
 * in from the profile with withOrigin().
 */
final class FeedFilterCriteria
{
    public const SORTS = ['date', 'distance', 'rating'];

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

    /** @param array<string,mixed> $input normally $_GET */
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

    /** Returns a copy with the user's location set (properties are readonly). */
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

    /** Sorting by distance only makes sense if we know where the user is. */
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
