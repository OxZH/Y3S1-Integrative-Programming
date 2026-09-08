<?php
// A facility owner account and its payout details. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Model;

use App\AccountStatus;
use App\UserType;
use DateTimeImmutable;

/**
 * The `FacilityOwner` subclass of the class table inheritance mapping. Owns
 * venues (module 1) and receives booking payouts (module 4).
 *
 * The bank account number is the most sensitive field this module stores, so it
 * is never handed out whole: getMaskedBankAccountNum() is what the profile
 * screen and the web service use. The raw value has exactly one legitimate
 * reader - the payment module at payout time - and that goes through a
 * dedicated service call, not through the profile.
 */
final class FacilityOwner extends Account
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
        private string $bankName = '',
        private string $bankAccountNum = '',
        private string $businessRegNum = ''
    ) {
        parent::__construct(
            $baseUserId,
            $email,
            $username,
            $contactNumber,
            UserType::FACILITY_OWNER,
            $accountStatus,
            $registerTime,
            $lastLoginAt,
            $passwordChangedAt
        );
    }

    public function getBankName(): string
    {
        return $this->bankName;
    }

    public function getBusinessRegNum(): string
    {
        return $this->businessRegNum;
    }

    public function getBankAccountNum(): string
    {
        return $this->bankAccountNum;
    }

    /** Last four digits only - enough to confirm which account, useless to copy. */
    public function getMaskedBankAccountNum(): string
    {
        $length = mb_strlen($this->bankAccountNum);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . mb_substr($this->bankAccountNum, -4);
    }

    public function setBankName(string $bankName): void
    {
        $this->bankName = $bankName;
    }

    public function setBankAccountNum(string $bankAccountNum): void
    {
        $this->bankAccountNum = $bankAccountNum;
    }

    public function setBusinessRegNum(string $businessRegNum): void
    {
        $this->businessRegNum = $businessRegNum;
    }
}
