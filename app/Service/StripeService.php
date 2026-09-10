<?php
// Stripe Test Mode adapter for payments and Connect transfers. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Throwable;

$composer = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (is_file($composer)) {
    require_once $composer;
}

final class StripeService
{
    private object $client;

    public function __construct()
    {
        if (!class_exists(\Stripe\StripeClient::class)) {
            throw new RuntimeException('Stripe is not installed. Run composer install first.');
        }

        $secret = (string) config('payment.stripe_secret', '');

        if ($secret === '') {
            throw new RuntimeException('Stripe Test Mode is not configured.');
        }

        $this->client = new \Stripe\StripeClient($secret);
    }

    /** @return array{id:string,clientSecret:string,status:string} */
    public function createPaymentIntent(
        string $localId,
        float $amount,
        string $description,
        array $metadata
    ): array {
        if ($amount < 2.00) {
            throw new RuntimeException('Stripe requires a payment of at least RM2.00.');
        }

        $intent = $this->execute(fn () => $this->client->paymentIntents->create([
            'amount'                    => (int) round($amount * 100),
            'currency'                  => (string) config('payment.currency', 'myr'),
            'description'               => $description,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata'                  => $metadata + ['localId' => $localId],
        ], ['idempotency_key' => 'intent-' . $localId]));

        return [
            'id'           => (string) $intent->id,
            'clientSecret' => (string) $intent->client_secret,
            'status'       => (string) $intent->status,
        ];
    }

    /** @return array{id:string,detailsSubmitted:bool,chargesEnabled:bool,payoutsEnabled:bool} */
    public function createConnectAccount(string $baseUserId, string $email): array
    {
        $account = $this->execute(fn () => $this->client->accounts->create([
            'type'         => 'express',
            'country'      => (string) config('payment.connect_country', 'MY'),
            'email'        => $email,
            'capabilities' => ['transfers' => ['requested' => true]],
            'metadata'     => ['baseUserId' => $baseUserId],
        ], ['idempotency_key' => 'connect-' . $baseUserId]));

        return $this->accountData($account);
    }

    /** @return array{id:string,detailsSubmitted:bool,chargesEnabled:bool,payoutsEnabled:bool} */
    public function retrieveConnectAccount(string $stripeAccountId): array
    {
        return $this->accountData($this->execute(
            fn () => $this->client->accounts->retrieve($stripeAccountId, [])
        ));
    }

    public function createAccountLink(string $stripeAccountId, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->execute(fn () => $this->client->accountLinks->create([
            'account'     => $stripeAccountId,
            'return_url'  => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type'        => 'account_onboarding',
        ]));

        return (string) $link->url;
    }

    public function createTransfer(
        string $localTransferId,
        string $stripeAccountId,
        float $amount,
        string $eventId
    ): string {
        $transfer = $this->execute(fn () => $this->client->transfers->create([
            'amount'         => (int) round($amount * 100),
            'currency'       => (string) config('payment.currency', 'myr'),
            'destination'    => $stripeAccountId,
            'transfer_group' => 'event-' . $eventId,
            'metadata'       => ['localTransferId' => $localTransferId, 'eventId' => $eventId],
        ], ['idempotency_key' => 'transfer-' . $localTransferId]));

        return (string) $transfer->id;
    }

    public function refundPaymentIntent(string $localRefundId, string $paymentIntentId): string
    {
        $refund = $this->execute(fn () => $this->client->refunds->create([
            'payment_intent' => $paymentIntentId,
        ], ['idempotency_key' => 'refund-' . $localRefundId]));

        return (string) $refund->id;
    }

    public static function constructWebhook(string $payload, string $signature): object
    {
        if (!class_exists(\Stripe\Webhook::class)) {
            throw new RuntimeException('Stripe is not installed. Run composer install first.');
        }

        $secret = (string) config('payment.webhook_secret', '');

        if ($secret === '') {
            throw new RuntimeException('Stripe webhook signing secret is not configured.');
        }

        return \Stripe\Webhook::constructEvent($payload, $signature, $secret);
    }

    /** @return array{id:string,detailsSubmitted:bool,chargesEnabled:bool,payoutsEnabled:bool} */
    private function accountData(object $account): array
    {
        return [
            'id'               => (string) $account->id,
            'detailsSubmitted' => (bool) $account->details_submitted,
            'chargesEnabled'   => (bool) $account->charges_enabled,
            'payoutsEnabled'   => (bool) $account->payouts_enabled,
        ];
    }

    private function execute(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            error_log('Stripe request failed: ' . $e->getMessage());
            throw new RuntimeException('Stripe could not complete the request. Please try again.');
        }
    }
}
