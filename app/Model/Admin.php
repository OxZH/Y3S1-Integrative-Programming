<?php
// An administrator account. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Model;

use App\AccountStatus;
use App\UserType;
use DateTimeImmutable;

/**
 * The `Admin` subclass of the class table inheritance mapping.
 *
 * There is no registration path that produces one of these. An administrator is
 * seeded or promoted by another administrator, so posting userType=ADMIN at the
 * sign-up form cannot mint one.
 */
final class Admin extends Account
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
        private string $adminId = ''
    ) {
        parent::__construct(
            $baseUserId,
            $email,
            $username,
            $contactNumber,
            UserType::ADMIN,
            $accountStatus,
            $registerTime,
            $lastLoginAt,
            $passwordChangedAt
        );
    }

    /** The staff number, separate from the opaque baseUserId. */
    public function getAdminId(): string
    {
        return $this->adminId;
    }

    public function setAdminId(string $adminId): void
    {
        $this->adminId = $adminId;
    }

    public function getDisplayName(): string
    {
        return $this->username . ' (admin)';
    }
}
