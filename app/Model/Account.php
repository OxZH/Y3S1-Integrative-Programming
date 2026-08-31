<?php
// Placeholder for the User Authentication module's account classes. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;

/**
 * Stands in for BaseUser / User / FacilityOwner, which belong to the User
 * Authentication & Profile Management module. This module only reads accounts,
 * so it keeps the fields needed to show an owner or a host and nothing more.
 * Replace with that module's entities when they are available.
 *
 * The password hash is deliberately never selected - a column that is not
 * loaded cannot leak through a var_dump or a json_encode.
 */
class Account extends Entity
{
    public function __construct(
        private string $baseUserId,
        private string $username,
        private string $email,
        private string $contactNumber,
        private string $role,
        private string $accountStatus = 'ACTIVE',
        private ?string $bankName = null,
        private ?string $businessRegNum = null
    ) {
    }

    public function getIdentity(): ?string
    {
        return $this->baseUserId;
    }

    public function getBaseUserId(): string
    {
        return $this->baseUserId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getContactNumber(): string
    {
        return $this->contactNumber;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function getBusinessRegNum(): ?string
    {
        return $this->businessRegNum;
    }

    public function isFacilityOwner(): bool
    {
        return $this->role === 'FACILITY_OWNER';
    }

    public function isActive(): bool
    {
        return $this->accountStatus === 'ACTIVE';
    }
}
