<?php
// Facility search filters. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Domain;

final class SearchCriteria
{
    /**
     * Allow-list for ORDER BY. A column name cannot be a bound parameter, so a
     * ?sort= value that is not a key here never becomes SQL.
     */
    public const SORTS = [
        'name'     => 'f.`name`',
        'price'    => 'f.`bookingFee`',
        'city'     => 'f.`city`',
        'newest'   => 'f.`createdAt`',
        'distance' => 'distanceKm',
    ];

    public function __construct(
        public readonly ?string $keyword = null,
        public readonly ?string $city = null,
        public readonly ?string $type = null,
        public readonly ?float $maxFee = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $radiusKm = null,
        public readonly string $sortBy = 'name',
        public readonly string $direction = 'ASC',
        public readonly int $limit = 50
    ) {
    }

    /** @param array<string,mixed> $input usually $_GET */
    public static function fromArray(array $input): self
    {
        $sortBy = is_string($input['sort'] ?? null) ? $input['sort'] : 'name';

        if (!array_key_exists($sortBy, self::SORTS)) {
            $sortBy = 'name';
        }

        $latitude  = self::toFloat($input['lat'] ?? null);
        $longitude = self::toFloat($input['lng'] ?? null);

        if ($sortBy === 'distance' && ($latitude === null || $longitude === null)) {
            $sortBy = 'name';
        }

        return new self(
            keyword:   self::toText($input['q'] ?? null),
            city:      self::toText($input['city'] ?? null),
            type:      self::toText($input['type'] ?? null),
            maxFee:    self::toFloat($input['max_fee'] ?? null),
            latitude:  $latitude,
            longitude: $longitude,
            radiusKm:  self::toFloat($input['radius'] ?? null),
            sortBy:    $sortBy,
            direction: strtoupper((string) ($input['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC',
            limit:     min(100, max(1, (int) ($input['limit'] ?? 50)))
        );
    }

    public function hasOrigin(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function sortExpression(): string
    {
        return self::SORTS[$this->sortBy] ?? self::SORTS['name'];
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
}
