<?php
// A player account and its profile. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Model;

use App\AccountStatus;
use App\UserType;
use DateTimeImmutable;

/**
 * The `User` subclass of the class table inheritance mapping: the shared columns
 * come from BaseUser, the profile columns below come from the `User` table.
 *
 * This is the role the platform is built around - a player who hosts events,
 * joins them and is rated by other players.
 */
final class User extends Account
{
    public function __construct(
        string $baseUserId,
        string $email,
        string $username,
        string $contactNumber,
        AccountStatus $accountStatus = AccountStatus::ACTIVE,
        ?DateTimeImmutable $registerTime = null,
        ?DateTimeImmutable $lastLoginAt = null,
        ?DateTimeImmutable $passwordChangedAt = null,
        private ?string $favoriteSport = null,
        private ?string $location = null,
        private ?string $profilePicURL = null,
        private ?DateTimeImmutable $birthDate = null,
        private ?float $latitude = null,
        private ?float $longitude = null
    ) {
        parent::__construct(
            $baseUserId,
            $email,
            $username,
            $contactNumber,
            UserType::USER,
            $accountStatus,
            $registerTime,
            $lastLoginAt,
            $passwordChangedAt
        );
    }

    public function getFavoriteSport(): ?string
    {
        return $this->favoriteSport;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getProfilePicURL(): ?string
    {
        return $this->profilePicURL;
    }

    public function getBirthDate(): ?DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setFavoriteSport(?string $sport): void
    {
        $this->favoriteSport = $sport;
    }

    public function setLocation(?string $location): void
    {
        $this->location = $location;
    }

    public function setProfilePicURL(?string $url): void
    {
        $this->profilePicURL = $url;
    }

    public function setBirthDate(?DateTimeImmutable $birthDate): void
    {
        $this->birthDate = $birthDate;
    }

    public function setCoordinates(?float $latitude, ?float $longitude): void
    {
        // Both or neither. Half a coordinate pair is not a location, and the
        // distance sort would read the missing half as zero.
        if ($latitude === null || $longitude === null) {
            $this->latitude  = null;
            $this->longitude = null;

            return;
        }

        $this->latitude  = $latitude;
        $this->longitude = $longitude;
    }

    /**
     * Derived rather than stored, so it cannot drift out of date. The profile
     * form asks for a birth date; every screen that wants "age" asks here.
     */
    public function getAge(): ?int
    {
        if ($this->birthDate === null) {
            return null;
        }

        return $this->birthDate->diff(new DateTimeImmutable('today'))->y;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
