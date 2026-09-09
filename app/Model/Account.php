<?php
// Placeholder for the User Authentication module's account classes. Author: Goh Jian Yu

namespace App\Model;

use App\Core\Entity;

// Stands in for BaseUser / User / FacilityOwner, which belong to the User
// Authentication & Profile Management module. This module only reads accounts,
// so it keeps the fields needed to show an owner or a host and nothing more.
// Replace it with that module's entities once they are available.
//
// The password hash is never selected on purpose. A column that is not loaded
// cannot leak through a var_dump or a json_encode.
class Account extends Entity
{
    private $baseUserId;
    private $username;
    private $email;
    private $contactNumber;
    private $role;
    private $accountStatus;
    private $bankName;
    private $businessRegNum;

    public function __construct(
        $baseUserId,
        $username,
        $email,
        $contactNumber,
        $role,
        $accountStatus = 'ACTIVE',
        $bankName = null,
        $businessRegNum = null
    ) {
        $this->baseUserId = $baseUserId;
        $this->username = $username;
        $this->email = $email;
        $this->contactNumber = $contactNumber;
        $this->role = $role;
        $this->accountStatus = $accountStatus;
        $this->bankName = $bankName;
        $this->businessRegNum = $businessRegNum;
    }

    public function getIdentity(): ?string
    {
        return $this->baseUserId;
    }

    public function getBaseUserId()
    {
        return $this->baseUserId;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getContactNumber()
    {
        return $this->contactNumber;
    }

    public function getBankName()
    {
        return $this->bankName;
    }

    public function getBusinessRegNum()
    {
        return $this->businessRegNum;
    }

    public function isFacilityOwner()
    {
        return $this->role === 'FACILITY_OWNER';
    }

    // Only a member can host a game. This matches the database: Event.hostId is
    // a foreign key to the User table, and a facility owner has no row there.
    public function isMember()
    {
        return $this->role === 'USER';
    }

    public function isActive()
    {
        return $this->accountStatus === 'ACTIVE';
    }
}
