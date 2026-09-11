<?php
// Demo FPX checkout: validate, mask and snapshot. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Domain\Payment;

use DomainException;

final class FpxPaymentStrategy implements PaymentMethodStrategy
{
    /** @return array<int,string> */
    public static function banks(): array
    {
        return [
            'Maybank',
            'CIMB',
            'Public Bank',
            'RHB',
            'Hong Leong',
            'Bank Islam',
            'Other',
        ];
    }

    public function code(): string
    {
        return 'fpx';
    }

    public function label(): string
    {
        return 'FPX Online Banking';
    }

    public function validate(array $input): array
    {
        $bankName = trim((string) ($input['bankName'] ?? ''));

        if (!in_array($bankName, self::banks(), true)) {
            throw new DomainException('Select a bank for FPX.');
        }

        $accountHolder = trim((string) ($input['accountHolder'] ?? ''));

        if (strlen($accountHolder) < 2 || strlen($accountHolder) > 100) {
            throw new DomainException('Enter the account holder name.');
        }

        $accountNumber = preg_replace('/\D+/', '', (string) ($input['accountNumber'] ?? '')) ?? '';

        if (strlen($accountNumber) < 8 || strlen($accountNumber) > 20) {
            throw new DomainException('Enter a bank account number of 8 to 20 digits.');
        }

        return [
            'bankName' => $bankName,
            'accountHolder' => $accountHolder,
            'accountLast4' => substr($accountNumber, -4),
        ];
    }

    public function snapshot(array $normalized): array
    {
        $last4 = (string) ($normalized['accountLast4'] ?? '');

        return [
            'paymentMethod' => $this->code(),
            'payerName' => (string) ($normalized['accountHolder'] ?? ''),
            'accountMask' => '****' . $last4,
            'providerLabel' => (string) ($normalized['bankName'] ?? ''),
            'methodDetailJson' => [
                'bankName' => (string) ($normalized['bankName'] ?? ''),
                'accountHolder' => (string) ($normalized['accountHolder'] ?? ''),
                'accountLast4' => $last4,
            ],
        ];
    }

    public function formFields(): array
    {
        return [
            ['name' => 'bankName', 'label' => 'Bank', 'type' => 'select', 'options' => self::banks()],
            ['name' => 'accountHolder', 'label' => 'Account holder', 'type' => 'text'],
            ['name' => 'accountNumber', 'label' => 'Account number', 'type' => 'text'],
        ];
    }
}
