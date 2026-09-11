<?php
// Demo e-wallet checkout: validate, mask and snapshot. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Domain\Payment;

use DomainException;

final class EWalletPaymentStrategy implements PaymentMethodStrategy
{
    /** @return array<int,string> */
    public static function providers(): array
    {
        return [
            "Touch 'n Go",
            'GrabPay',
            'Boost',
            'Other',
        ];
    }

    public function code(): string
    {
        return 'e_wallet';
    }

    public function label(): string
    {
        return 'E-wallet';
    }

    public function validate(array $input): array
    {
        $walletProvider = trim((string) ($input['walletProvider'] ?? ''));

        if (!in_array($walletProvider, self::providers(), true)) {
            throw new DomainException('Select an e-wallet provider.');
        }

        $walletAccount = preg_replace('/[\s-]+/', '', (string) ($input['walletAccount'] ?? '')) ?? '';

        if ($walletAccount === '' || strlen($walletAccount) < 6 || strlen($walletAccount) > 20) {
            throw new DomainException('Enter the wallet phone number or account id.');
        }

        if (preg_match('/^[A-Za-z0-9+]+$/', $walletAccount) !== 1) {
            throw new DomainException('Enter a valid wallet phone number or account id.');
        }

        return [
            'walletProvider' => $walletProvider,
            'walletAccount' => $walletAccount,
            'displayMask' => $this->mask($walletAccount),
        ];
    }

    public function snapshot(array $normalized): array
    {
        $mask = (string) ($normalized['displayMask'] ?? '');

        return [
            'paymentMethod' => $this->code(),
            'payerName' => (string) ($normalized['walletProvider'] ?? $this->label()),
            'accountMask' => $mask,
            'providerLabel' => (string) ($normalized['walletProvider'] ?? $this->label()),
            'methodDetailJson' => [
                'walletProvider' => (string) ($normalized['walletProvider'] ?? ''),
                'walletAccount' => (string) ($normalized['walletAccount'] ?? ''),
                'displayMask' => $mask,
            ],
        ];
    }

    public function formFields(): array
    {
        return [
            ['name' => 'walletProvider', 'label' => 'Wallet', 'type' => 'select', 'options' => self::providers()],
            ['name' => 'walletAccount', 'label' => 'Wallet phone or account id', 'type' => 'text'],
        ];
    }

    private function mask(string $account): string
    {
        $last4 = substr($account, -4);
        $digits = preg_replace('/\D+/', '', $account) ?? '';

        if (strlen($digits) >= 9 && str_starts_with($digits, '01')) {
            return '+60****' . $last4;
        }

        return '****' . $last4;
    }
}
