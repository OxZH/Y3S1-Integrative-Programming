<?php
// Facility entity. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use App\FacilityStatus;
use DateTimeImmutable;

/**
 * A bookable venue.
 *
 * Times of day are kept as zero padded 'H:i:s' strings, so ordinary string
 * comparison gives the right answer and MySQL compares them the same way.
 */
class Facility extends Entity
{
    protected ?Account $owner = null;

    public function __construct(
        private ?string $facilityId,
        private string $name,
        private string $addressLine,
        private string $city,
        private string $state,
        private string $type,
        private float $bookingFee,
        private string $operationalHrsStart,
        private string $operationalHrsEnd,
        private float $latitude,
        private float $longitude,
        private FacilityStatus $status = FacilityStatus::PENDING,
        private ?string $imageUrl = null,
        private ?DateTimeImmutable $createdAt = null
    ) {
        $this->createdAt ??= new DateTimeImmutable();
    }

    public function getIdentity(): ?string
    {
        return $this->facilityId;
    }

    public function getFacilityId(): ?string
    {
        return $this->facilityId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddressLine(): string
    {
        return $this->addressLine;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getBookingFee(): float
    {
        return $this->bookingFee;
    }

    public function getOperationalHrsStart(): string
    {
        return $this->operationalHrsStart;
    }

    public function getOperationalHrsEnd(): string
    {
        return $this->operationalHrsEnd;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getStatus(): FacilityStatus
    {
        return $this->status;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOwner(): ?Account
    {
        return $this->resolve('owner');
    }

    public function setOwner(Account $owner): void
    {
        $this->owner = $owner;
    }

    public function getFullAddress(): string
    {
        return sprintf('%s, %s, %s', $this->addressLine, $this->city, $this->state);
    }

    public function isBookable(): bool
    {
        return $this->status->isBookable();
    }

    public function isWithinOperatingHours(string $startTime, string $endTime): bool
    {
        return $startTime >= $this->operationalHrsStart && $endTime <= $this->operationalHrsEnd;
    }

    // Haversine great circle distance in km.
    public function distanceFromKm(float $latitude, float $longitude): float
    {
        $deltaLat = deg2rad($latitude - $this->latitude);
        $deltaLon = deg2rad($longitude - $this->longitude);

        $a = sin($deltaLat / 2) ** 2
           + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) * sin($deltaLon / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function approve(): void
    {
        $this->status = FacilityStatus::ACTIVE;
    }

    public function suspend(): void
    {
        $this->status = FacilityStatus::SUSPENDED;
    }

    /** @param array<string,mixed> $changes */
    public function updateDetails(array $changes): void
    {
        $this->name                = $changes['name'] ?? $this->name;
        $this->addressLine         = $changes['addressLine'] ?? $this->addressLine;
        $this->city                = $changes['city'] ?? $this->city;
        $this->state               = $changes['state'] ?? $this->state;
        $this->type                = $changes['type'] ?? $this->type;
        $this->bookingFee          = $changes['bookingFee'] ?? $this->bookingFee;
        $this->operationalHrsStart = $changes['operationalHrsStart'] ?? $this->operationalHrsStart;
        $this->operationalHrsEnd   = $changes['operationalHrsEnd'] ?? $this->operationalHrsEnd;
        $this->latitude            = $changes['latitude'] ?? $this->latitude;
        $this->longitude           = $changes['longitude'] ?? $this->longitude;
        $this->imageUrl            = $changes['imageUrl'] ?? $this->imageUrl;
    }
}
