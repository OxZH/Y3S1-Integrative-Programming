<?php
/**
 * Venue Booking & Payment web service. Author: Khor Zhi Hong
 *
 * INTERFACE AGREEMENT
 * Protocol      JSON over HTTP POST, using the shared IFA S/F/E envelope
 * Source Module Venue Booking & Payment
 * Target Module Event & Facility Management
 * URL           /api/payment.php
 *
 * getBookingStatus
 *   Request:  requestId, timeStamp, function, eventId
 *   Success:  bookingId, bookingStatus, paymentStatus, amount
 *
 * getEventPaymentSummary
 *   Request:  requestId, timeStamp, function, eventId
 *   Success:  venue, participants and payout totals
 *
 * cancelEventPayments
 *   Request:  requestId, timeStamp, function, eventId, reason; X-Service-Key
 *   Effect:   reverses the venue transfer and fully refunds all event payments
 *
 * settleEventPayout
 *   Request:  requestId, timeStamp, function, eventId; X-Service-Key
 *   Effect:   after COMPLETED, transfers collected participant fees to organizer
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Domain\PaymentFacade;
use App\Service\Ifa;
use App\Service\ServiceLog;

$request = Ifa::readRequest();
$requestId = is_string($request['requestId'] ?? null) ? $request['requestId'] : uuid();
$problem = Ifa::validateRequest($request);

if ($problem !== null) {
    Ifa::respond(Ifa::fail($requestId, $problem), 400);
}

$function = is_string($request['function'] ?? null) ? $request['function'] : 'unknown';
$eventId = is_string($request['eventId'] ?? null) ? $request['eventId'] : '';

ServiceLog::start(
    $requestId,
    ServiceLog::INBOUND,
    is_string($request['sourceModule'] ?? null) ? $request['sourceModule'] : 'unknown',
    'Venue Booking & Payment',
    $function,
    (string) $request['timeStamp']
);

try {
    if ($eventId === '') {
        throw new DomainException('eventId is mandatory.');
    }

    $facade = new PaymentFacade();

    if ($function === 'getBookingStatus') {
        $data = $facade->getBookingStatus($eventId);
        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, $data, 'Booking status retrieved.'));
    }

    if ($function === 'getEventPaymentSummary') {
        $data = $facade->eventPaymentSummary($eventId);
        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, $data, 'Payment summary retrieved.'));
    }

    if (in_array($function, ['cancelEventPayments', 'settleEventPayout'], true)) {
        $providedKey = is_string($_SERVER['HTTP_X_SERVICE_KEY'] ?? null)
            ? $_SERVER['HTTP_X_SERVICE_KEY']
            : '';
        $expectedKey = (string) config('payment.service_key', '');

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 403, 'Invalid service key.');
            Ifa::respond(Ifa::fail($requestId, 'This operation requires a valid service key.'), 403);
        }

        if ($function === 'cancelEventPayments') {
            $reason = is_string($request['reason'] ?? null) && trim($request['reason']) !== ''
                ? trim($request['reason'])
                : 'Event cancelled by organizer.';
            $facade->cancelEventPayments($eventId, $reason);
            $data = $facade->eventPaymentSummary($eventId);
            ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
            Ifa::respond(Ifa::success($requestId, $data, 'Event payments cancelled and refunded.'));
        }

        $data = $facade->settleEventPayout($eventId);
        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, $data, 'Participant fees settled to organizer.'));
    }

    ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'Unknown function.');
    Ifa::respond(
        Ifa::fail(
            $requestId,
            'Unknown function. Use getBookingStatus, getEventPaymentSummary, cancelEventPayments or settleEventPayout.'
        ),
        400
    );
} catch (DomainException $e) {
    ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 409, $e->getMessage());
    Ifa::respond(Ifa::fail($requestId, $e->getMessage()), 409);
} catch (Throwable $e) {
    error_log('api/payment: ' . $e->getMessage());
    ServiceLog::finish($requestId, Ifa::STATUS_ERROR, 500, $e->getMessage());
    Ifa::respond(Ifa::error($requestId), 500);
}
