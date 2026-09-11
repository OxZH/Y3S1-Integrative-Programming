<?php
// Resolves a checkout strategy from a method code. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Domain\Payment;

use DomainException;

final class PaymentMethodStrategyFactory
{
    public static function fromCode(string $code): PaymentMethodStrategy
    {
        $code = $code === 'e-wallet' ? 'e_wallet' : $code;

        return match ($code) {
            'card' => new CardPaymentStrategy(),
            'fpx' => new FpxPaymentStrategy(),
            'e_wallet' => new EWalletPaymentStrategy(),
            default => throw new DomainException('Select a valid payment method.'),
        };
    }

    /** @return array<int,PaymentMethodStrategy> */
    public static function all(): array
    {
        return [
            new CardPaymentStrategy(),
            new FpxPaymentStrategy(),
            new EWalletPaymentStrategy(),
        ];
    }

    public static function displayLabel(?string $code): string
    {
        $code = trim((string) $code);

        if ($code === '' || $code === 'not_selected') {
            return '';
        }

        try {
            return self::fromCode($code)->label();
        } catch (DomainException) {
            return $code;
        }
    }
}
