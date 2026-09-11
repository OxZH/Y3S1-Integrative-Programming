<?php
/**
 * Venue Booking & Payment web service (provider). Author: Khor Zhi Hong
 *
 * INTERFACE AGREEMENT
 *
 * Webservice Mechanism  RESTful, JSON over HTTP POST
 * Protocol              HTTP + JSON  (shared IFA envelope: requestId, timeStamp, status S/F/E)
 * Source Module         Venue Booking & Payment
 * Target Module         Event & Facility Management
 * URL                   /api/payment.php
 * Function Name         getBookingStatus, getEventPaymentSummary,
 *                       cancelEventPayments, settleEventPayout
 *
 * Same wire format as Event (`/api/event.php`) and Facility (`/api/facility.php`):
 * one POST URL, operation chosen by `function` in the JSON body. Not SOAP.
 *
 * ---------------------------------------------------------------------------
 *  Shared request fields (every function)
 * ---------------------------------------------------------------------------
 *  Field       Type     M/O        Description                    Format
 *  requestId   String   Mandatory  Unique id of the call          UUID, <= 36 chars
 *  timeStamp   String   Mandatory  When the request was made      YYYY-MM-DD HH:MM:SS
 *  function    String   Mandatory  Operation wanted               see below
 *  eventId     String   Mandatory  Event the payment belongs to   UUID / seed id
 *  sourceModule String  Optional   Caller's module name           for WebServiceLog
 *
 * ---------------------------------------------------------------------------
 *  Shared response fields (every function)
 * ---------------------------------------------------------------------------
 *  Field       Type     M/O        Description                    Format
 *  status      String   Mandatory  Result of the request          S / F / E
 *  requestId   String   Mandatory  Echo of the request id
 *  timeStamp   String   Mandatory  When the response was made     YYYY-MM-DD HH:MM:SS
 *  message     String   Mandatory  Outcome in words
 *  data        Object   Optional   Null unless status is S
 *
 * ---------------------------------------------------------------------------
 *  FUNCTION: getBookingStatus
 *    Description : Venue booking and payment status for an event.
 *                  Jianyu uses this to decide whether an event may publish.
 *    data on S   : bookingId, bookingStatus, paymentStatus, amount
 *
 *  FUNCTION: getEventPaymentSummary
 *    Description : Venue fee, collected participant fees and payout state.
 *    data on S   : eventId, venue {bookingStatus, paymentStatus, amount},
 *                  participants {payments, collected, refunded},
 *                  payout {amount, status}
 *
 *  FUNCTION: cancelEventPayments
 *    Description : Refunds participant fees before the event starts.
 *                  Venue fees are non-refundable.
 *    Extra       : reason (String, Optional); header X-Service-Key (Mandatory)
 *    data on S   : same shape as getEventPaymentSummary
 *
 *  FUNCTION: settleEventPayout
 *    Description : After COMPLETED, reports collected participant fees as settled
 *                  to the organizer.
 *    Extra       : header X-Service-Key (Mandatory)
 *    data on S   : eventId, organizerId, amount, status
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Domain\PaymentService;
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

    $payments = new PaymentService();

    if ($function === 'getBookingStatus') {
        $data = $payments->getBookingStatus($eventId);
        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, $data, 'Booking status retrieved.'));
    }

    if ($function === 'getEventPaymentSummary') {
        $data = $payments->eventPaymentSummary($eventId);
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
            $payments->cancelEventPayments($eventId, $reason);
            $data = $payments->eventPaymentSummary($eventId);
            ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
            Ifa::respond(Ifa::success(
                $requestId,
                $data,
                'Eligible participant fees refunded. Venue payments are non-refundable.'
            ));
        }

        $data = $payments->settleEventPayout($eventId);
        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, $data, 'Participant fees settled to organizer.'));
    }

    ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'Unknown function: ' . $function);
    Ifa::respond(
        Ifa::fail(
            $requestId,
            'Unknown function. This endpoint offers getBookingStatus, getEventPaymentSummary, cancelEventPayments and settleEventPayout.'
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
