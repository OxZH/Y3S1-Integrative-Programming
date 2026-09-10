<?php
// Venue and participant payment orchestration. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;
use App\Service\PaymentRemoteServices;
use App\Service\StripeService;
use DateTimeImmutable;
use DomainException;
use PDO;
use RuntimeException;

final class PaymentFacade
{
    private PDO $db;
    private PaymentRemoteServices $remote;
    private ?StripeService $stripe = null;

    public function __construct(?PaymentRemoteServices $remote = null)
    {
        $this->db = Database::getConnection();
        $this->remote = $remote ?? new PaymentRemoteServices();
    }

    /** @return array<string,mixed> */
    public function prepareVenueCheckout(string $eventId, string $userId): array
    {
        $event = $this->remote->eventDetails($eventId, $userId);
        $hostId = (string) ($event['host']['baseUserId'] ?? '');

        if ($hostId !== $userId) {
            throw new DomainException('Only the event organizer can pay for this venue.');
        }

        if (!in_array((string) ($event['status'] ?? ''), ['DRAFT', 'PENDING_PAYMENT'], true)) {
            throw new DomainException('This event is not waiting for a venue payment.');
        }

        $facilityId = (string) ($event['facility']['facilityId'] ?? '');
        $facility = $this->remote->facilityDetails($facilityId);
        $payeeId = (string) ($facility['owner']['baseUserId'] ?? '');
        $this->requireReadyConnectAccount($payeeId, 'The facility owner must finish Stripe Connect onboarding before payment.');

        $amount = round((float) ($facility['bookingFee'] ?? 0) * (float) ($event['durationHours'] ?? 0), 2);

        if ($amount <= 0) {
            throw new DomainException('The venue payment amount is invalid.');
        }
        if ($amount < 2.00) {
            throw new DomainException('Stripe requires the venue charge to be at least RM2.00.');
        }

        $payment = $this->reserveVenuePayment($eventId, $userId, $amount);
        $this->reserveVenueTransfer($eventId, $payeeId, $amount);

        if (($payment['paymentStatus'] ?? null) === 'PAID') {
            return compact('event', 'facility', 'amount') + [
                'kind' => 'venue', 'status' => 'PAID', 'clientSecret' => null,
            ];
        }

        $paymentId = (string) $payment['paymentId'];

        $intent = $this->stripe()->createPaymentIntent(
            $paymentId,
            $amount,
            'Venue booking for ' . (string) ($event['name'] ?? $eventId),
            ['paymentType' => 'VENUE', 'eventId' => $eventId, 'paymentId' => $paymentId]
        );

        $this->execute(
            'UPDATE `Payment` SET `stripePaymentIntentId` = :stripe
              WHERE `paymentId` = :id AND `paymentStatus` <> :paid',
            [
                ':stripe' => $intent['id'], ':id' => $paymentId, ':paid' => 'PAID',
            ]
        );

        return compact('event', 'facility', 'amount') + [
            'kind' => 'venue', 'status' => 'PENDING', 'clientSecret' => $intent['clientSecret'],
        ];
    }

    /** @return array<string,mixed> */
    public function prepareParticipantCheckout(string $eventId, string $userId): array
    {
        $this->requirePlayer($userId);
        $event = $this->remote->eventDetails($eventId, $userId);
        $organizerId = (string) ($event['host']['baseUserId'] ?? '');

        if ($organizerId === $userId) {
            throw new DomainException('The organizer cannot join their own event as a participant.');
        }

        if (!in_array((string) ($event['status'] ?? ''), ['PUBLISHED', 'FULL'], true)) {
            throw new DomainException('This event is not accepting participant payments.');
        }

        if ((int) ($event['spacesLeft'] ?? 0) < 1) {
            throw new DomainException('This event is full.');
        }

        $this->assertEventHasNotStarted($event);
        $amount = round((float) ($event['feePerParticipant'] ?? 0), 2);

        if ($amount > 0 && $amount < 2.00) {
            throw new DomainException('Stripe requires the participant fee to be either free or at least RM2.00.');
        }

        if ($amount > 0) {
            $this->requireReadyConnectAccount(
                $organizerId,
                'The organizer must finish Stripe Connect onboarding before accepting participant fees.'
            );
        }

        $registrationId = $this->reserveParticipantPlace(
            $eventId,
            $userId,
            (int) ($event['maxParticipants'] ?? 0)
        );
        $payment = $this->one(
            'SELECT * FROM `ParticipantPayment` WHERE `eventRegistrationId` = :id LIMIT 1',
            [':id' => $registrationId]
        );
        if ($payment === null) {
            $participantPaymentId = uuid();
            $this->execute(
                'INSERT IGNORE INTO `ParticipantPayment`
                    (`participantPaymentId`, `eventRegistrationId`, `eventId`, `participantId`,
                     `organizerId`, `amount`, `paymentStatus`)
                 VALUES (:id, :registration, :event, :participant, :organizer, :amount, :status)',
                [
                    ':id' => $participantPaymentId, ':registration' => $registrationId,
                    ':event' => $eventId, ':participant' => $userId, ':organizer' => $organizerId,
                    ':amount' => $this->decimal($amount), ':status' => $amount <= 0 ? 'PAID' : 'PENDING',
                ]
            );
            $payment = $this->one(
                'SELECT * FROM `ParticipantPayment` WHERE `eventRegistrationId` = :id LIMIT 1',
                [':id' => $registrationId]
            );
        }

        if ($payment === null) {
            throw new RuntimeException('The participant payment could not be prepared.');
        }

        if (
            (string) $payment['participantId'] !== $userId
            || (string) $payment['organizerId'] !== $organizerId
            || abs((float) $payment['amount'] - $amount) > 0.001
        ) {
            throw new DomainException('The existing participant payment does not match this event fee.');
        }

        $participantPaymentId = (string) $payment['participantPaymentId'];

        if ((string) $payment['paymentStatus'] === 'PAID' && $amount > 0) {
            return compact('event', 'amount') + [
                'kind' => 'participant', 'status' => 'PAID', 'clientSecret' => null,
            ];
        }

        if ((string) $payment['paymentStatus'] === 'REFUNDED') {
            throw new DomainException('A refunded registration cannot be reopened. Please contact the organizer.');
        }

        if ($amount <= 0) {
            $this->execute(
                'UPDATE `EventRegistration` SET `status` = :status WHERE `eventRegistrationId` = :id',
                [':status' => 'CONFIRMED', ':id' => $registrationId]
            );

            return compact('event', 'amount') + [
                'kind' => 'participant', 'status' => 'PAID', 'clientSecret' => null,
            ];
        }

        $intent = $this->stripe()->createPaymentIntent(
            $participantPaymentId,
            $amount,
            'Participant fee for ' . (string) ($event['name'] ?? $eventId),
            [
                'paymentType' => 'PARTICIPANT', 'eventId' => $eventId,
                'participantPaymentId' => $participantPaymentId,
            ]
        );

        $this->execute(
            'UPDATE `ParticipantPayment`
                SET `stripePaymentIntentId` = :stripe
              WHERE `participantPaymentId` = :id',
            [':stripe' => $intent['id'], ':id' => $participantPaymentId]
        );

        return compact('event', 'amount') + [
            'kind' => 'participant', 'status' => 'PENDING', 'clientSecret' => $intent['clientSecret'],
        ];
    }

    /** @return array<string,mixed> */
    public function getBookingStatus(string $eventId): array
    {
        $row = $this->one(
            'SELECT b.`bookingId`, b.`bookingStatus`, b.`bookingAmount`,
                    COALESCE(p.`paymentStatus`, :pending) AS `paymentStatus`
               FROM `Booking` b
               LEFT JOIN `Payment` p ON p.`bookingId` = b.`bookingId`
              WHERE b.`eventId` = :event LIMIT 1',
            [':pending' => 'PENDING', ':event' => $eventId]
        );

        if ($row === null) {
            throw new DomainException('No booking exists for that event.');
        }

        return [
            'bookingId' => (string) $row['bookingId'],
            'bookingStatus' => (string) $row['bookingStatus'],
            'paymentStatus' => (string) $row['paymentStatus'],
            'amount' => (float) $row['bookingAmount'],
        ];
    }

    /** @return array<string,mixed> */
    public function eventPaymentSummary(string $eventId): array
    {
        $venue = $this->one(
            'SELECT b.`bookingStatus`, b.`bookingAmount`,
                    COALESCE(p.`paymentStatus`, :pending) AS `paymentStatus`
               FROM `Booking` b LEFT JOIN `Payment` p ON p.`bookingId` = b.`bookingId`
              WHERE b.`eventId` = :event LIMIT 1',
            [':pending' => 'PENDING', ':event' => $eventId]
        );
        $participants = $this->one(
            'SELECT COUNT(*) AS `payments`,
                    COALESCE(SUM(CASE WHEN `paymentStatus` = :paid THEN `amount` ELSE 0 END), 0) AS `collected`,
                    COALESCE(SUM(CASE WHEN `paymentStatus` = :refunded THEN `amount` ELSE 0 END), 0) AS `refunded`
               FROM `ParticipantPayment` WHERE `eventId` = :event',
            [':paid' => 'PAID', ':refunded' => 'REFUNDED', ':event' => $eventId]
        );
        $payout = $this->one(
            'SELECT `amount`, `status`, `stripeTransferId`
               FROM `PaymentTransfer`
              WHERE `eventId` = :event AND `transferType` = :type LIMIT 1',
            [':event' => $eventId, ':type' => 'PARTICIPANT_PAYOUT']
        );

        return [
            'eventId' => $eventId,
            'venue' => $venue === null ? null : [
                'bookingStatus' => (string) $venue['bookingStatus'],
                'paymentStatus' => (string) $venue['paymentStatus'],
                'amount' => (float) $venue['bookingAmount'],
            ],
            'participants' => [
                'payments' => (int) ($participants['payments'] ?? 0),
                'collected' => (float) ($participants['collected'] ?? 0),
                'refunded' => (float) ($participants['refunded'] ?? 0),
            ],
            'payout' => $payout === null ? null : [
                'amount' => (float) $payout['amount'],
                'status' => (string) $payout['status'],
                'stripeTransferId' => $payout['stripeTransferId'],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function paymentsForUser(string $userId): array
    {
        return $this->all(
            'SELECT pp.`eventId`, e.`name`, pp.`amount`, pp.`paymentStatus`, pp.`createdAt`,
                    :participant AS `kind`
               FROM `ParticipantPayment` pp JOIN `Event` e ON e.`eventId` = pp.`eventId`
              WHERE pp.`participantId` = :user
              UNION ALL
             SELECT b.`eventId`, e.`name`, b.`bookingAmount`,
                    COALESCE(p.`paymentStatus`, :pending), b.`createdAt`, :venue
               FROM `Booking` b JOIN `Event` e ON e.`eventId` = b.`eventId`
               LEFT JOIN `Payment` p ON p.`bookingId` = b.`bookingId`
              WHERE b.`madeById` = :user2
              ORDER BY `createdAt` DESC',
            [
                ':participant' => 'PARTICIPANT', ':user' => $userId, ':pending' => 'PENDING',
                ':venue' => 'VENUE', ':user2' => $userId,
            ]
        );
    }

    /** @return array<string,mixed>|null */
    public function connectStatus(string $baseUserId): ?array
    {
        return $this->one(
            'SELECT * FROM `ConnectAccount` WHERE `baseUserId` = :id LIMIT 1',
            [':id' => $baseUserId]
        );
    }

    public function beginConnectOnboarding(
        string $baseUserId,
        string $email,
        string $returnUrl,
        string $refreshUrl
    ): string {
        $row = $this->connectStatus($baseUserId);

        if ($row === null) {
            $account = $this->stripe()->createConnectAccount($baseUserId, $email);
            $this->saveConnectAccount($baseUserId, $account);
            $stripeAccountId = $account['id'];
        } else {
            $stripeAccountId = (string) $row['stripeAccountId'];
        }

        return $this->stripe()->createAccountLink($stripeAccountId, $returnUrl, $refreshUrl);
    }

    /** @return array<string,mixed> */
    public function refreshConnectAccount(string $baseUserId): array
    {
        $row = $this->connectStatus($baseUserId);

        if ($row === null) {
            throw new DomainException('No Stripe Connect account exists for this user.');
        }

        $account = $this->stripe()->retrieveConnectAccount((string) $row['stripeAccountId']);
        $this->saveConnectAccount($baseUserId, $account);

        return $account;
    }

    public function syncConnectAccountByStripeId(string $stripeAccountId): void
    {
        $row = $this->one(
            'SELECT `baseUserId` FROM `ConnectAccount` WHERE `stripeAccountId` = :id LIMIT 1',
            [':id' => $stripeAccountId]
        );

        if ($row !== null) {
            $account = $this->stripe()->retrieveConnectAccount($stripeAccountId);
            $this->saveConnectAccount((string) $row['baseUserId'], $account);
        }
    }

    public function cancelParticipantPayment(string $eventId, string $userId): void
    {
        $event = $this->remote->eventDetails($eventId, $userId);
        $this->assertEventHasNotStarted($event);

        $row = $this->one(
            'SELECT pp.*, er.`status` AS `registrationStatus`
               FROM `ParticipantPayment` pp
               JOIN `EventRegistration` er ON er.`eventRegistrationId` = pp.`eventRegistrationId`
              WHERE pp.`eventId` = :event AND pp.`participantId` = :user LIMIT 1',
            [':event' => $eventId, ':user' => $userId]
        );

        if ($row === null) {
            throw new DomainException('No participant payment exists for this event.');
        }

        $this->refundParticipantRow($row, 'Participant cancelled before the event started.');
    }

    public function cancelEventPayments(string $eventId, string $reason): void
    {
        $organizer = $this->one(
            'SELECT `hostId` FROM `Event` WHERE `eventId` = :event LIMIT 1',
            [':event' => $eventId]
        );

        if ($organizer === null) {
            throw new DomainException('The event does not exist.');
        }

        $event = $this->remote->eventDetails($eventId, (string) $organizer['hostId']);

        // Venue bookings are non-refundable. Participant fees are refundable
        // only until the event's actual start date and time.
        if ($this->eventHasStarted($event)) {
            return;
        }

        foreach ($this->all(
            'SELECT * FROM `ParticipantPayment` WHERE `eventId` = :event',
            [':event' => $eventId]
        ) as $participant) {
            $this->refundParticipantRow($participant, $reason);
        }
    }

    /** @return array<string,mixed> */
    public function settleEventPayout(string $eventId): array
    {
        $organizer = $this->one(
            'SELECT `hostId` FROM `Event` WHERE `eventId` = :event LIMIT 1',
            [':event' => $eventId]
        );

        if ($organizer === null) {
            throw new DomainException('The event does not exist.');
        }

        $organizerId = (string) $organizer['hostId'];
        $event = $this->remote->eventDetails($eventId, $organizerId);

        if ((string) ($event['status'] ?? '') !== 'COMPLETED') {
            throw new DomainException('Participant fees can only be paid out after the event is completed.');
        }

        $existing = $this->one(
            'SELECT * FROM `PaymentTransfer`
              WHERE `eventId` = :event AND `transferType` = :type LIMIT 1',
            [':event' => $eventId, ':type' => 'PARTICIPANT_PAYOUT']
        );

        if (($existing['status'] ?? null) === 'PAID') {
            return $existing;
        }

        $sum = $this->one(
            'SELECT COALESCE(SUM(`amount`), 0) AS `amount`
               FROM `ParticipantPayment`
              WHERE `eventId` = :event AND `paymentStatus` = :status',
            [':event' => $eventId, ':status' => 'PAID']
        );
        $amount = round((float) ($sum['amount'] ?? 0), 2);

        if ($amount <= 0) {
            return ['eventId' => $eventId, 'amount' => 0.0, 'status' => 'PAID'];
        }

        $account = $this->requireReadyConnectAccount(
            $organizerId,
            'The organizer must finish Stripe Connect onboarding before payout.'
        );
        $transferId = $existing === null ? uuid() : (string) $existing['transferId'];

        if ($existing === null) {
            $this->execute(
                'INSERT INTO `PaymentTransfer`
                    (`transferId`, `eventId`, `recipientId`, `transferType`, `amount`, `status`)
                 VALUES (:id, :event, :recipient, :type, :amount, :status)',
                [
                    ':id' => $transferId, ':event' => $eventId, ':recipient' => $organizerId,
                    ':type' => 'PARTICIPANT_PAYOUT', ':amount' => $this->decimal($amount), ':status' => 'PENDING',
                ]
            );
        }

        $stripeTransferId = $this->stripe()->createTransfer(
            $transferId,
            (string) $account['stripeAccountId'],
            $amount,
            $eventId
        );
        $this->execute(
            'UPDATE `PaymentTransfer` SET `stripeTransferId` = :stripe, `status` = :status
              WHERE `transferId` = :id',
            [':stripe' => $stripeTransferId, ':status' => 'PAID', ':id' => $transferId]
        );

        return ['eventId' => $eventId, 'amount' => $amount, 'status' => 'PAID', 'stripeTransferId' => $stripeTransferId];
    }

    public function handleIntentSucceeded(string $paymentType, string $localId, string $stripeIntentId): void
    {
        if ($paymentType === 'VENUE') {
            Database::transaction(function () use ($localId, $stripeIntentId): void {
                $this->execute(
                    'UPDATE `Payment` SET `paymentStatus` = :paid, `paymentDateTime` = NOW(),
                            `stripePaymentIntentId` = :stripe
                      WHERE `paymentId` = :id',
                    [':paid' => 'PAID', ':stripe' => $stripeIntentId, ':id' => $localId]
                );
                $this->execute(
                    'UPDATE `Booking` b JOIN `Payment` p ON p.`bookingId` = b.`bookingId`
                        SET b.`bookingStatus` = :confirmed
                      WHERE p.`paymentId` = :id',
                    [':confirmed' => 'CONFIRMED', ':id' => $localId]
                );
            });
            $this->releaseVenueTransfer($localId);

            return;
        }

        if ($paymentType === 'PARTICIPANT') {
            Database::transaction(function () use ($localId, $stripeIntentId): void {
                $this->execute(
                    'UPDATE `ParticipantPayment`
                        SET `paymentStatus` = :paid, `paidAt` = NOW(), `stripePaymentIntentId` = :stripe
                      WHERE `participantPaymentId` = :id',
                    [':paid' => 'PAID', ':stripe' => $stripeIntentId, ':id' => $localId]
                );
                $this->execute(
                    'UPDATE `EventRegistration` er
                       JOIN `ParticipantPayment` pp
                         ON pp.`eventRegistrationId` = er.`eventRegistrationId`
                        SET er.`status` = :confirmed
                      WHERE pp.`participantPaymentId` = :id',
                    [':confirmed' => 'CONFIRMED', ':id' => $localId]
                );
            });
        }
    }

    public function handleIntentFailed(string $paymentType, string $localId): void
    {
        if ($paymentType === 'VENUE') {
            $this->execute(
                'UPDATE `Payment` SET `paymentStatus` = :failed WHERE `paymentId` = :id AND `paymentStatus` <> :paid',
                [':failed' => 'FAILED', ':id' => $localId, ':paid' => 'PAID']
            );
        } elseif ($paymentType === 'PARTICIPANT') {
            Database::transaction(function () use ($localId): void {
                $this->execute(
                    'UPDATE `ParticipantPayment` SET `paymentStatus` = :failed
                      WHERE `participantPaymentId` = :id AND `paymentStatus` <> :paid',
                    [':failed' => 'FAILED', ':id' => $localId, ':paid' => 'PAID']
                );
                $this->execute(
                    'UPDATE `EventRegistration` er
                       JOIN `ParticipantPayment` pp
                         ON pp.`eventRegistrationId` = er.`eventRegistrationId`
                        SET er.`status` = :cancelled
                      WHERE pp.`participantPaymentId` = :id AND pp.`paymentStatus` = :failed',
                    [':cancelled' => 'CANCELLED', ':id' => $localId, ':failed' => 'FAILED']
                );
            });
        }
    }

    public function handleChargeRefunded(string $stripePaymentIntentId, bool $fullyRefunded): void
    {
        $venue = $this->one(
            'SELECT `paymentId`, `bookingId` FROM `Payment`
              WHERE `stripePaymentIntentId` = :stripe LIMIT 1',
            [':stripe' => $stripePaymentIntentId]
        );

        if ($venue !== null) {
            $this->execute(
                'UPDATE `Payment` SET `paymentStatus` = :status WHERE `paymentId` = :id',
                [':status' => $fullyRefunded ? 'REFUNDED' : 'PARTIALLY_REFUNDED', ':id' => $venue['paymentId']]
            );
            if ($fullyRefunded) {
                $this->execute(
                    'UPDATE `Booking` SET `bookingStatus` = :status WHERE `bookingId` = :id',
                    [':status' => 'CANCELLED', ':id' => $venue['bookingId']]
                );
            }

            return;
        }

        $participant = $this->one(
            'SELECT `participantPaymentId`, `eventRegistrationId` FROM `ParticipantPayment`
              WHERE `stripePaymentIntentId` = :stripe LIMIT 1',
            [':stripe' => $stripePaymentIntentId]
        );

        if ($participant !== null) {
            $this->execute(
                'UPDATE `ParticipantPayment` SET `paymentStatus` = :status
                  WHERE `participantPaymentId` = :id',
                [
                    ':status' => $fullyRefunded ? 'REFUNDED' : 'PARTIALLY_REFUNDED',
                    ':id' => $participant['participantPaymentId'],
                ]
            );
            if ($fullyRefunded) {
                $this->execute(
                    'UPDATE `EventRegistration` SET `status` = :status WHERE `eventRegistrationId` = :id',
                    [':status' => 'CANCELLED', ':id' => $participant['eventRegistrationId']]
                );
            }
        }
    }

    public function handleTransferEvent(string $stripeTransferId, string $eventType, float $reversedAmount = 0): void
    {
        $status = match ($eventType) {
            'transfer.reversed' => 'REVERSED',
            default             => 'PAID',
        };

        $this->execute(
            'UPDATE `PaymentTransfer`
                SET `status` = :status, `reversedAmount` = :reversed
              WHERE `stripeTransferId` = :stripe',
            [
                ':status' => $status, ':reversed' => $this->decimal($reversedAmount),
                ':stripe' => $stripeTransferId,
            ]
        );
    }

    private function releaseVenueTransfer(string $paymentId): void
    {
        $row = $this->one(
            'SELECT p.`amount`, b.`eventId`, t.`transferId`, t.`recipientId`,
                    t.`status`, t.`stripeTransferId`
               FROM `Payment` p
               JOIN `Booking` b ON b.`bookingId` = p.`bookingId`
               JOIN `PaymentTransfer` t
                 ON t.`eventId` = b.`eventId` AND t.`transferType` = :type
              WHERE p.`paymentId` = :id AND p.`paymentStatus` = :paid LIMIT 1',
            [':type' => 'VENUE', ':id' => $paymentId, ':paid' => 'PAID']
        );

        if ($row === null || (string) $row['status'] === 'PAID') {
            return;
        }

        $eventId = (string) $row['eventId'];
        $account = $this->requireReadyConnectAccount(
            (string) $row['recipientId'],
            'The facility owner Stripe Connect account is not ready.'
        );
        $transferId = (string) $row['transferId'];

        $stripeId = $this->stripe()->createTransfer(
            $transferId,
            (string) $account['stripeAccountId'],
            (float) $row['amount'],
            $eventId
        );
        $this->execute(
            'UPDATE `PaymentTransfer` SET `stripeTransferId` = :stripe, `status` = :paid
              WHERE `transferId` = :id',
            [':stripe' => $stripeId, ':paid' => 'PAID', ':id' => $transferId]
        );
    }

    /** @param array<string,mixed> $row */
    private function refundParticipantRow(array $row, string $reason): void
    {
        if ((string) $row['paymentStatus'] === 'PAID' && (float) $row['amount'] > 0) {
            $refund = $this->one(
                'SELECT * FROM `ParticipantRefund` WHERE `participantPaymentId` = :id LIMIT 1',
                [':id' => $row['participantPaymentId']]
            );
            $refundId = $refund === null ? uuid() : (string) $refund['participantRefundId'];

            if ($refund === null) {
                $this->execute(
                    'INSERT INTO `ParticipantRefund`
                        (`participantRefundId`, `participantPaymentId`, `datetime`, `amount`, `reason`)
                     VALUES (:id, :participant, NOW(), :amount, :reason)',
                    [
                        ':id' => $refundId, ':participant' => $row['participantPaymentId'],
                        ':amount' => $row['amount'], ':reason' => mb_substr($reason, 0, 255),
                    ]
                );
            }

            $stripeRefundId = $this->stripe()->refundPaymentIntent(
                $refundId,
                (string) $row['stripePaymentIntentId']
            );
            $this->execute(
                'UPDATE `ParticipantRefund` SET `stripeRefundId` = :stripe
                  WHERE `participantRefundId` = :id',
                [':stripe' => $stripeRefundId, ':id' => $refundId]
            );
        }

        $this->execute(
            'UPDATE `ParticipantPayment` SET `paymentStatus` = :status
              WHERE `participantPaymentId` = :id',
            [':status' => 'REFUNDED', ':id' => $row['participantPaymentId']]
        );
        $this->execute(
            'UPDATE `EventRegistration` SET `status` = :status
              WHERE `eventRegistrationId` = :id',
            [':status' => 'CANCELLED', ':id' => $row['eventRegistrationId']]
        );
    }

    /** @return array<string,mixed> */
    private function requireReadyConnectAccount(string $baseUserId, string $message): array
    {
        $row = $this->connectStatus($baseUserId);

        if ($row === null || !(bool) $row['payoutsEnabled']) {
            throw new DomainException($message);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function reserveVenuePayment(string $eventId, string $userId, float $amount): array
    {
        return Database::transaction(function () use ($eventId, $userId, $amount): array {
            if ($this->one(
                'SELECT `eventId` FROM `Event` WHERE `eventId` = :event FOR UPDATE',
                [':event' => $eventId]
            ) === null) {
                throw new DomainException('The event does not exist.');
            }

            $booking = $this->one(
                'SELECT * FROM `Booking` WHERE `eventId` = :event LIMIT 1',
                [':event' => $eventId]
            );

            if ($booking === null) {
                $bookingId = uuid();
                $this->execute(
                    'INSERT INTO `Booking`
                        (`bookingId`, `eventId`, `madeById`, `bookingStatus`, `bookingAmount`)
                     VALUES (:id, :event, :maker, :status, :amount)',
                    [
                        ':id' => $bookingId, ':event' => $eventId, ':maker' => $userId,
                        ':status' => 'PENDING', ':amount' => $this->decimal($amount),
                    ]
                );
            } else {
                $bookingId = (string) $booking['bookingId'];

                if (
                    (string) $booking['madeById'] !== $userId
                    || abs((float) $booking['bookingAmount'] - $amount) > 0.001
                ) {
                    throw new DomainException('The existing booking does not match this event quote.');
                }
            }

            $payment = $this->one(
                'SELECT * FROM `Payment` WHERE `bookingId` = :booking LIMIT 1',
                [':booking' => $bookingId]
            );

            if ($payment === null) {
                $paymentId = uuid();
                $this->execute(
                    'INSERT INTO `Payment`
                        (`paymentId`, `bookingId`, `amount`, `paymentDateTime`, `paymentMethod`, `paymentStatus`)
                     VALUES (:id, :booking, :amount, NOW(), :method, :status)',
                    [
                        ':id' => $paymentId, ':booking' => $bookingId, ':amount' => $this->decimal($amount),
                        ':method' => 'stripe', ':status' => 'PENDING',
                    ]
                );
                $payment = $this->one(
                    'SELECT * FROM `Payment` WHERE `paymentId` = :id LIMIT 1',
                    [':id' => $paymentId]
                );
            }

            if ($payment === null) {
                throw new RuntimeException('The venue payment could not be prepared.');
            }

            return $payment;
        });
    }

    private function reserveVenueTransfer(string $eventId, string $payeeId, float $amount): void
    {
        $transfer = $this->one(
            'SELECT * FROM `PaymentTransfer`
              WHERE `eventId` = :event AND `transferType` = :type LIMIT 1',
            [':event' => $eventId, ':type' => 'VENUE']
        );

        if ($transfer === null) {
            $this->execute(
                'INSERT IGNORE INTO `PaymentTransfer`
                    (`transferId`, `eventId`, `recipientId`, `transferType`, `amount`, `status`)
                 VALUES (:id, :event, :recipient, :type, :amount, :status)',
                [
                    ':id' => uuid(), ':event' => $eventId, ':recipient' => $payeeId,
                    ':type' => 'VENUE', ':amount' => $this->decimal($amount), ':status' => 'PENDING',
                ]
            );
            $transfer = $this->one(
                'SELECT * FROM `PaymentTransfer`
                  WHERE `eventId` = :event AND `transferType` = :type LIMIT 1',
                [':event' => $eventId, ':type' => 'VENUE']
            );
        }

        if (
            $transfer === null
            || (string) $transfer['recipientId'] !== $payeeId
            || abs((float) $transfer['amount'] - $amount) > 0.001
        ) {
            throw new DomainException('The venue transfer does not match the facility owner or booking amount.');
        }
    }

    private function reserveParticipantPlace(string $eventId, string $userId, int $maximum): string
    {
        if ($maximum < 1) {
            throw new DomainException('This event has no participant capacity.');
        }

        return Database::transaction(function () use ($eventId, $userId, $maximum): string {
            // Locking the Event row serializes competing registrations for the
            // same event, so two last-place checkouts cannot both reserve it.
            $event = $this->one(
                'SELECT `eventId` FROM `Event` WHERE `eventId` = :event FOR UPDATE',
                [':event' => $eventId]
            );

            if ($event === null) {
                throw new DomainException('The event does not exist.');
            }

            $registration = $this->one(
                'SELECT * FROM `EventRegistration`
                  WHERE `userId` = :user AND `eventId` = :event LIMIT 1',
                [':user' => $userId, ':event' => $eventId]
            );

            if ($registration !== null && in_array(
                (string) $registration['status'],
                ['PENDING', 'CONFIRMED', 'ATTENDED'],
                true
            )) {
                if ((string) $registration['status'] !== 'PENDING') {
                    throw new DomainException('You are already registered for this event.');
                }

                return (string) $registration['eventRegistrationId'];
            }

            $count = $this->one(
                'SELECT COUNT(*) AS `total` FROM `EventRegistration`
                  WHERE `eventId` = :event AND `status` IN (:pending, :confirmed, :attended)',
                [
                    ':event' => $eventId, ':pending' => 'PENDING',
                    ':confirmed' => 'CONFIRMED', ':attended' => 'ATTENDED',
                ]
            );

            if ((int) ($count['total'] ?? 0) >= $maximum) {
                throw new DomainException('This event is full.');
            }

            if ($registration === null) {
                $registrationId = uuid();
                $this->execute(
                    'INSERT INTO `EventRegistration`
                        (`eventRegistrationId`, `userId`, `eventId`, `status`)
                     VALUES (:id, :user, :event, :status)',
                    [
                        ':id' => $registrationId, ':user' => $userId,
                        ':event' => $eventId, ':status' => 'PENDING',
                    ]
                );

                return $registrationId;
            }

            $registrationId = (string) $registration['eventRegistrationId'];
            $this->execute(
                'UPDATE `EventRegistration` SET `status` = :status WHERE `eventRegistrationId` = :id',
                [':status' => 'PENDING', ':id' => $registrationId]
            );

            return $registrationId;
        });
    }

    private function requirePlayer(string $baseUserId): void
    {
        if ($this->one(
            'SELECT `baseUserId` FROM `User` WHERE `baseUserId` = :id LIMIT 1',
            [':id' => $baseUserId]
        ) === null) {
            throw new DomainException('Only a player account can pay a participant fee.');
        }
    }

    /** @param array<string,mixed> $event */
    private function assertEventHasNotStarted(array $event): void
    {
        if ($this->eventHasStarted($event)) {
            throw new DomainException('This action is only available before the event starts.');
        }
    }

    /** @param array<string,mixed> $event */
    private function eventHasStarted(array $event): bool
    {
        $startsAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) ($event['eventDate'] ?? '') . ' ' . (string) ($event['startTime'] ?? '')
        );

        return $startsAt === false || $startsAt <= new DateTimeImmutable();
    }

    /** @param array{id:string,detailsSubmitted:bool,chargesEnabled:bool,payoutsEnabled:bool} $account */
    private function saveConnectAccount(string $baseUserId, array $account): void
    {
        $this->execute(
            'INSERT INTO `ConnectAccount`
                (`baseUserId`, `stripeAccountId`, `detailsSubmitted`, `chargesEnabled`, `payoutsEnabled`)
             VALUES (:user, :stripe, :details, :charges, :payouts)
             ON DUPLICATE KEY UPDATE
                `stripeAccountId` = VALUES(`stripeAccountId`),
                `detailsSubmitted` = VALUES(`detailsSubmitted`),
                `chargesEnabled` = VALUES(`chargesEnabled`),
                `payoutsEnabled` = VALUES(`payoutsEnabled`)',
            [
                ':user' => $baseUserId, ':stripe' => $account['id'],
                ':details' => $account['detailsSubmitted'] ? 1 : 0,
                ':charges' => $account['chargesEnabled'] ? 1 : 0,
                ':payouts' => $account['payoutsEnabled'] ? 1 : 0,
            ]
        );
    }

    private function stripe(): StripeService
    {
        return $this->stripe ??= new StripeService();
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
    }

    private function decimal(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
