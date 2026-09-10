<?php
// Stripe-signed webhook endpoint. Author: Khor Zhi Hong

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Domain\PaymentFacade;
use App\Service\StripeService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!is_string($payload) || !is_string($signature) || $signature === '') {
    http_response_code(400);
    echo '{"received":false}';
    exit;
}

$facade = null;
$eventId = '';

try {
    $event = StripeService::constructWebhook($payload, $signature);
    $facade = new PaymentFacade();
    $eventId = (string) $event->id;
    $eventType = (string) $event->type;

    if (!$facade->claimWebhook($eventId, $eventType)) {
        echo '{"received":true,"duplicate":true}';
        exit;
    }

    $object = $event->data->object;

    if ($eventType === 'payment_intent.succeeded') {
        $paymentType = (string) ($object->metadata->paymentType ?? '');
        $localId = $paymentType === 'VENUE'
            ? (string) ($object->metadata->paymentId ?? '')
            : (string) ($object->metadata->participantPaymentId ?? '');

        $facade->handleIntentSucceeded($paymentType, $localId, (string) $object->id);
    } elseif ($eventType === 'payment_intent.payment_failed') {
        $paymentType = (string) ($object->metadata->paymentType ?? '');
        $localId = $paymentType === 'VENUE'
            ? (string) ($object->metadata->paymentId ?? '')
            : (string) ($object->metadata->participantPaymentId ?? '');

        $facade->handleIntentFailed($paymentType, $localId);
    } elseif ($eventType === 'charge.refunded') {
        $facade->handleChargeRefunded(
            (string) ($object->payment_intent ?? ''),
            (bool) ($object->refunded ?? false)
        );
    } elseif ($eventType === 'account.updated') {
        $facade->syncConnectAccountByStripeId((string) $object->id);
    } elseif (in_array($eventType, ['transfer.created', 'transfer.updated', 'transfer.reversed'], true)) {
        $facade->handleTransferEvent(
            (string) $object->id,
            $eventType,
            ((float) ($object->amount_reversed ?? 0)) / 100
        );
    }

    $facade->markWebhookProcessed($eventId);
    echo '{"received":true}';
} catch (UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    echo '{"received":false}';
} catch (Throwable $e) {
    if ($facade instanceof PaymentFacade && $eventId !== '') {
        try {
            $facade->releaseWebhook($eventId);
        } catch (Throwable) {
            // Stripe will retry; preserve the original error below.
        }
    }
    error_log('Stripe webhook failed: ' . $e->getMessage());
    http_response_code(500);
    echo '{"received":false}';
}
