<?php
// Abstract base of the account hierarchy. Author: Ivan Lim Tze Yang

namespace App\Model;

use App\AccountStatus;
use App\Core\Entity;
use App\UserType;
use DateTimeImmutable;

/**
 * BaseUser on the class diagram, mapped with class table inheritance: the shared
 * attributes live here and on the `BaseUser` table, and each role adds its own
 * subclass and its own table.
 *
 * The class is named Account rather than BaseUser because the other modules
 * already type-hint App\Model\Account for "whoever this is" - a facility's
 * owner, an event's host. Keeping the name means module 1 needed no change when
 * this hierarchy replaced its read-only placeholder.
 *
 * The password hash is deliberately NOT a property. Nothing that renders a
 * profile, serialises an owner into a web service response or var_dumps an
 * entity during debugging can leak a hash that was never loaded. Verifying a
 * password goes through AccountMapper::credentialsForEmail(), which returns a
 * plain array to the authentication service and hydrates no object.
 */
abstract class Account extends Entity
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
        protected string $baseUserId,
        protected string $email,
        protected string $username,
        protected string $contactNumber,
        protected UserType $userType,
        protected AccountStatus $accountStatus = AccountStatus::ACTIVE,
        protected ?DateTimeImmutable $registerTime = null,
        protected ?DateTimeImmutable $lastLoginAt = null,
        protected ?DateTimeImmutable $passwordChangedAt = null
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getContactNumber(): string
    {
        return $this->contactNumber;
    }

    public function getUserType(): UserType
    {
        return $this->userType;
    }

    public function getAccountStatus(): AccountStatus
    {
        return $this->accountStatus;
    }

    public function getRegisterTime(): ?DateTimeImmutable
    {
        return $this->registerTime;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getPasswordChangedAt(): ?DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function isFacilityOwner()
    {
        return $this->userType === UserType::FACILITY_OWNER;
    }

    public function isAdmin(): bool
    {
        return $this->userType === UserType::ADMIN;
    }

    public function isPlayer(): bool
    {
        return $this->userType === UserType::USER;
    }

    // Only a member can host a game. This matches the database: Event.hostId is
    // a foreign key to the User table, and a facility owner has no row there.
    public function isMember()
    {
        return $this->role === 'USER';
    }

    public function isActive()
    {
        return $this->accountStatus->isActive();
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function setContactNumber(string $contactNumber): void
    {
        $this->contactNumber = $contactNumber;
    }

    public function setAccountStatus(AccountStatus $status): void
    {
        $this->accountStatus = $status;
    }

    /** A screen name for lists and headings. */
    public function getDisplayName(): string
    {
        return $this->username;
    }

    /**
     * Masked for anywhere the whole address is not actually needed - the admin
     * account list, the audit trail. Showing enough to recognise your own
     * address, not enough to harvest someone else's.
     */
    public function getMaskedEmail(): string
    {
        $at = strpos($this->email, '@');

        if ($at === false || $at < 1) {
            return str_repeat('*', mb_strlen($this->email));
        }

        $name   = substr($this->email, 0, $at);
        $domain = substr($this->email, $at);
        $keep   = mb_substr($name, 0, 2);

        return $keep . str_repeat('*', max(1, mb_strlen($name) - 2)) . $domain;
    }
}
