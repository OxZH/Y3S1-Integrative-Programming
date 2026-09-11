<?php
// Demo card checkout: validate, mask and snapshot. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Domain\Payment;

use DateTimeImmutable;
use DomainException;

final class CardPaymentStrategy implements PaymentMethodStrategy
{
    public function code(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function validate(array $input): array
    {
        $holderName = trim((string) ($input['holderName'] ?? ''));

        if (strlen($holderName) < 2 || strlen($holderName) > 100) {
            throw new DomainException('Enter the cardholder name.');
        }

        $digits = preg_replace('/\D+/', '', (string) ($input['cardNumber'] ?? '')) ?? '';

        if (strlen($digits) < 13 || strlen($digits) > 19) {
            throw new DomainException('Enter a card number of 13 to 19 digits.');
        }

        $month = (string) ($input['expiryMonth'] ?? '');
        $year = (string) ($input['expiryYear'] ?? '');

        if (!preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
            throw new DomainException('Enter a valid expiry month.');
        }

        if (preg_match('/^\d{4}$/', $year) === 1) {
            $year = substr($year, -2);
        }

        if (!preg_match('/^\d{2}$/', $year)) {
            throw new DomainException('Enter a valid expiry year.');
        }

        $expiry = DateTimeImmutable::createFromFormat('!y-m', $year . '-' . $month);

        if ($expiry === false) {
            throw new DomainException('Enter a valid expiry date.');
        }

        $endOfMonth = $expiry->modify('last day of this month 23:59:59');

        if ($endOfMonth < new DateTimeImmutable('today')) {
            throw new DomainException('That card has expired.');
        }

        $cvv = preg_replace('/\D+/', '', (string) ($input['cvv'] ?? '')) ?? '';

        if (strlen($cvv) < 3 || strlen($cvv) > 4) {
            throw new DomainException('Enter a 3 or 4 digit CVV.');
        }

        return [
            'holderName' => $holderName,
            'last4' => substr($digits, -4),
            'brand' => $this->brandFor($digits),
            'expiryMonth' => $month,
            'expiryYear' => $year,
        ];
    }

    public function snapshot(array $normalized): array
    {
        $last4 = (string) ($normalized['last4'] ?? '');

        return [
            'paymentMethod' => $this->code(),
            'payerName' => (string) ($normalized['holderName'] ?? ''),
            'accountMask' => '**** **** **** ' . $last4,
            'providerLabel' => (string) ($normalized['brand'] ?? 'Card'),
            'methodDetailJson' => [
                'holderName' => (string) ($normalized['holderName'] ?? ''),
                'last4' => $last4,
                'brand' => (string) ($normalized['brand'] ?? 'Card'),
                'expiryMonth' => (string) ($normalized['expiryMonth'] ?? ''),
                'expiryYear' => (string) ($normalized['expiryYear'] ?? ''),
            ],
        ];
    }

    public function formFields(): array
    {
        return [
            ['name' => 'holderName', 'label' => 'Cardholder name', 'type' => 'text'],
            ['name' => 'cardNumber', 'label' => 'Card number', 'type' => 'text'],
            ['name' => 'expiryMonth', 'label' => 'Expiry month', 'type' => 'select'],
            ['name' => 'expiryYear', 'label' => 'Expiry year', 'type' => 'select'],
            ['name' => 'cvv', 'label' => 'CVV', 'type' => 'password'],
        ];
    }

    private function brandFor(string $digits): string
    {
        return match (substr($digits, 0, 1)) {
            '4' => 'Visa',
            '5' => 'Mastercard',
            '3' => 'Amex',
            '6' => 'Discover',
            default => 'Card',
        };
    }
}
