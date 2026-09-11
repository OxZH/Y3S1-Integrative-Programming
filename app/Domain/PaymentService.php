<?php
// Venue and participant payment orchestration. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;
use App\Domain\Payment\PaymentMethodStrategyFactory;
use App\Model\EventRegistration;
use App\Model\EventRegistrationMapper;
use App\Service\PaymentRemoteServices;
use DateTimeImmutable;
use DomainException;
use PDO;
use RuntimeException;

/**
 * A participant's place in a game is taken at the moment the fee is paid, not
 * before. Nothing is written for a paid game while the checkout page is open,
 * so a seat is never held by somebody who then walks away, and two people
 * looking at the last seat find out who has it when one of them pays.
 *
 * The registration row itself belongs to Discovery & Event Matchmaking. That
 * module's EventRegistrationMapper owns the row lock that keeps a full game
 * full, so the place is taken through it, inside the same transaction as the
 * payment row, and this class never writes EventRegistration directly.
 */
final class PaymentService
{
    public const MAX_SAVED_METHODS = 5;

    private PDO $db;
    private PaymentRemoteServices $remote;
    private EventRegistrationMapper $registrations;

    public function __construct(
        ?PaymentRemoteServices $remote = null,
        ?EventRegistrationMapper $registrations = null
    ) {
        $this->db = Database::getConnection();
        $this->remote = $remote ?? new PaymentRemoteServices();
        $this->registrations = $registrations ?? new EventRegistrationMapper();
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
        $amount = round((float) ($facility['bookingFee'] ?? 0) * (float) ($event['durationHours'] ?? 0), 2);

        if ($amount <= 0) {
            throw new DomainException('The venue payment amount is invalid.');
        }

        $payment = $this->reserveVenuePayment($eventId, $userId, $amount);

        if (($payment['paymentStatus'] ?? null) === 'PAID') {
            return compact('event', 'facility', 'amount') + [
                'kind' => 'venue', 'status' => 'PAID',
            ];
        }

        return compact('event', 'facility', 'amount') + [
            'kind' => 'venue', 'status' => 'PENDING',
        ];
    }

    /**
     * What the checkout page shows. For a paid game this writes nothing: the
     * fee, the game and whether there is room are a preview, and the place is
     * only taken when the payment goes through. A free game has no payment to
     * wait for, so its one atomic step happens here instead.
     *
     * @return array<string,mixed>
     */
    public function prepareParticipantCheckout(string $eventId, string $userId): array
    {
        $this->requirePlayer($userId);
        $event  = $this->participantEventOrFail($eventId, $userId);
        $amount = $this->participantFee($event);

        // Already in this game: nothing to pay, and the page sends them back.
        $existing = $this->registrations->findForUserAndEvent($userId, $eventId);

        if ($existing instanceof EventRegistration && $existing->isActive()) {
            return compact('event', 'amount') + [
                'kind' => 'participant', 'status' => 'PAID',
            ];
        }

        if ($amount <= 0) {
            $this->takePlace($event, $userId, 0.0, null);

            return compact('event', 'amount') + [
                'kind' => 'participant', 'status' => 'PAID',
            ];
        }

        // Advisory only. The count that decides is taken under the event row
        // lock inside confirmInternalPayment(), at the moment of payment.
        if ((int) ($event['spacesLeft'] ?? 0) < 1) {
            throw new DomainException('This event is full.');
        }

        return compact('event', 'amount') + [
            'kind' => 'participant', 'status' => 'PENDING',
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function confirmInternalPayment(
        string $eventId,
        string $payerId,
        string $kind,
        string $method,
        array $input = []
    ): array {
        $strategy = PaymentMethodStrategyFactory::fromCode($method);
        $savedId = trim((string) ($input['savedPaymentMethodId'] ?? ''));

        if ($savedId !== '') {
            $saved = $this->savedMethodRow($payerId, $savedId);

            if ($saved === null || (string) $saved['paymentMethod'] !== $strategy->code()) {
                throw new DomainException('That saved payment method is not available.');
            }

            $input = $this->mergeSavedInput($input, $saved);
        }

        $snapshot = $strategy->snapshot($strategy->validate($input));

        if ($kind === 'venue') {
            Database::transaction(function () use ($eventId, $payerId, $snapshot): void {
                $payment = $this->one(
                    'SELECT p.`paymentId`, p.`paymentStatus`, b.`bookingId`, b.`madeById`
                       FROM `Payment` p
                       JOIN `Booking` b ON b.`bookingId` = p.`bookingId`
                      WHERE b.`eventId` = :event FOR UPDATE',
                    [':event' => $eventId]
                );

                if ($payment === null || (string) $payment['madeById'] !== $payerId) {
                    throw new DomainException('This venue payment does not belong to you.');
                }

                if ((string) $payment['paymentStatus'] === 'PAID') {
                    throw new DomainException('This payment is already complete.');
                }

                $this->execute(
                    'UPDATE `Payment`
                        SET `paymentStatus` = :paid, `paymentMethod` = :method,
                            `payerName` = :payerName, `accountMask` = :accountMask,
                            `providerLabel` = :providerLabel, `methodDetailJson` = :detail,
                            `paymentDateTime` = NOW()
                      WHERE `paymentId` = :id',
                    $this->snapshotParams($snapshot) + [
                        ':paid' => 'PAID', ':id' => $payment['paymentId'],
                    ]
                );
                $this->execute(
                    'UPDATE `Booking` SET `bookingStatus` = :status WHERE `bookingId` = :id',
                    [':status' => 'CONFIRMED', ':id' => $payment['bookingId']]
                );
            });

            return $snapshot;
        }

        if ($kind !== 'participant') {
            throw new DomainException('The payment type is invalid.');
        }

        // The game is asked about again here, not trusted from the page: it may
        // have been cancelled, started, or filled while the form was open.
        $this->requirePlayer($payerId);
        $event  = $this->participantEventOrFail($eventId, $payerId);
        $amount = $this->participantFee($event);

        if ($amount <= 0) {
            throw new DomainException('This game has no fee to pay.');
        }

        $this->takePlace($event, $payerId, $amount, $snapshot);

        return $snapshot;
    }

    /**
     * The one step that commits a participant: the place is taken and the
     * payment recorded together, or neither is.
     *
     * registerIfSpaceAvailable() is Discovery & Event Matchmaking's, and it is
     * where the capacity check really lives - it locks the event row, so two
     * people paying for the last seat are served one at a time and the second
     * is told the game is full. Because Database::transaction() joins a
     * transaction that is already open, that lock is held until the payment
     * row below is written as well.
     *
     * @param array<string,mixed>      $event
     * @param array<string,mixed>|null $snapshot null for a free game
     */
    private function takePlace(array $event, string $userId, float $amount, ?array $snapshot): void
    {
        Database::transaction(function () use ($event, $userId, $amount, $snapshot): void {
            $registration = $this->registrations->registerIfSpaceAvailable(
                (string) $event['eventId'],
                $userId,
                (int) ($event['maxParticipants'] ?? 0)
            );

            $this->recordParticipantPayment($registration, $event, $amount, $snapshot);
        });
    }

    /**
     * One payment row per registration. A player who left and came back reuses
     * their registration row (that is how the registration mapper works), so
     * the refunded payment row that goes with it is brought back to PAID rather
     * than joined by a second one - the unique key would refuse that anyway.
     *
     * @param array<string,mixed>      $event
     * @param array<string,mixed>|null $snapshot
     */
    private function recordParticipantPayment(
        EventRegistration $registration,
        array $event,
        float $amount,
        ?array $snapshot
    ): void {
        $registrationId = (string) $registration->getEventRegistrationId();
        $organizerId    = (string) ($event['host']['baseUserId'] ?? '');

        $detail = $snapshot === null
            ? [':method' => null, ':payerName' => null, ':accountMask' => null, ':providerLabel' => null, ':detail' => null]
            : $this->snapshotParams($snapshot);

        $existing = $this->one(
            'SELECT `participantPaymentId` FROM `ParticipantPayment` WHERE `eventRegistrationId` = :id LIMIT 1',
            [':id' => $registrationId]
        );

        if ($existing === null) {
            $this->execute(
                'INSERT INTO `ParticipantPayment`
                    (`participantPaymentId`, `eventRegistrationId`, `eventId`, `participantId`,
                     `organizerId`, `amount`, `paymentStatus`, `paidAt`,
                     `paymentMethod`, `payerName`, `accountMask`, `providerLabel`, `methodDetailJson`)
                 VALUES (:id, :registration, :event, :participant, :organizer, :amount, :paid, NOW(),
                         :method, :payerName, :accountMask, :providerLabel, :detail)',
                $detail + [
                    ':id' => uuid(), ':registration' => $registrationId,
                    ':event' => (string) $event['eventId'], ':participant' => $registration->getUserId(),
                    ':organizer' => $organizerId, ':amount' => $this->decimal($amount), ':paid' => 'PAID',
                ]
            );

            return;
        }

        $this->execute(
            'UPDATE `ParticipantPayment`
                SET `paymentStatus` = :paid, `paidAt` = NOW(), `amount` = :amount,
                    `paymentMethod` = :method, `payerName` = :payerName,
                    `accountMask` = :accountMask, `providerLabel` = :providerLabel,
                    `methodDetailJson` = :detail
              WHERE `participantPaymentId` = :id',
            $detail + [
                ':paid' => 'PAID', ':amount' => $this->decimal($amount),
                ':id' => $existing['participantPaymentId'],
            ]
        );
    }

    /**
     * The game as it stands right now, or an explanation of why this person
     * cannot join it. Asked at checkout and asked again at payment, because
     * the answer can change in between.
     *
     * @return array<string,mixed>
     */
    private function participantEventOrFail(string $eventId, string $userId): array
    {
        $event = $this->remote->eventDetails($eventId, $userId);

        if ((string) ($event['host']['baseUserId'] ?? '') === $userId) {
            throw new DomainException('The organizer cannot join their own event as a participant.');
        }

        if (!in_array((string) ($event['status'] ?? ''), ['PUBLISHED', 'FULL'], true)) {
            throw new DomainException('This event is not accepting participant payments.');
        }

        $this->assertEventHasNotStarted($event);

        return $event;
    }

    /** @param array<string,mixed> $event */
    private function participantFee(array $event): float
    {
        return round((float) ($event['feePerParticipant'] ?? 0), 2);
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
        $event = $this->one(
            'SELECT `status` FROM `Event` WHERE `eventId` = :event LIMIT 1',
            [':event' => $eventId]
        );
        $isSettled = (string) ($event['status'] ?? '') === 'COMPLETED';

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
            'payout' => [
                'amount' => (float) ($participants['collected'] ?? 0),
                'status' => $isSettled ? 'PAID' : 'PENDING',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function paymentsForUser(string $userId): array
    {
        return $this->all(
            'SELECT pp.`eventId`, e.`name`, pp.`amount`, pp.`paymentStatus`, pp.`createdAt`,
                    :participantOut AS `kind`, :outgoing1 AS `direction`,
                    organizer.`username` AS `counterparty`,
                    pp.`paymentMethod`, pp.`payerName`, pp.`accountMask`, pp.`providerLabel`
               FROM `ParticipantPayment` pp
               JOIN `Event` e ON e.`eventId` = pp.`eventId`
               JOIN `BaseUser` organizer ON organizer.`baseUserId` = pp.`organizerId`
              WHERE pp.`participantId` = :participantId
              UNION ALL
             SELECT b.`eventId`, e.`name`, b.`bookingAmount`,
                    COALESCE(p.`paymentStatus`, :pending), b.`createdAt`,
                    :venueOut, :outgoing2, owner.`username`,
                    p.`paymentMethod`, p.`payerName`, p.`accountMask`, p.`providerLabel`
               FROM `Booking` b JOIN `Event` e ON e.`eventId` = b.`eventId`
               LEFT JOIN `Payment` p ON p.`bookingId` = b.`bookingId`
               JOIN `Facility` f ON f.`facilityId` = e.`facilityId`
               JOIN `BaseUser` owner ON owner.`baseUserId` = f.`ownerId`
              WHERE b.`madeById` = :organizerId
              UNION ALL
             SELECT b.`eventId`, e.`name`, p.`amount`, p.`paymentStatus`,
                    p.`paymentDateTime`, :venueIn, :incoming1, payer.`username`,
                    p.`paymentMethod`, p.`payerName`, p.`accountMask`, p.`providerLabel`
               FROM `Payment` p
               JOIN `Booking` b ON b.`bookingId` = p.`bookingId`
               JOIN `Event` e ON e.`eventId` = b.`eventId`
               JOIN `Facility` f ON f.`facilityId` = e.`facilityId`
               JOIN `BaseUser` payer ON payer.`baseUserId` = b.`madeById`
              WHERE f.`ownerId` = :ownerId AND p.`paymentStatus` = :venuePaid
              UNION ALL
             SELECT pp.`eventId`, e.`name`, pp.`amount`, pp.`paymentStatus`,
                    pp.`paidAt`, :participantIn, :incoming2, payer.`username`,
                    pp.`paymentMethod`, pp.`payerName`, pp.`accountMask`, pp.`providerLabel`
               FROM `ParticipantPayment` pp
               JOIN `Event` e ON e.`eventId` = pp.`eventId`
               JOIN `BaseUser` payer ON payer.`baseUserId` = pp.`participantId`
              WHERE pp.`organizerId` = :hostId
                AND pp.`paymentStatus` = :participantPaid
                AND e.`status` = :completed
              ORDER BY `createdAt` DESC',
            [
                ':participantOut' => 'PARTICIPANT_FEE', ':outgoing1' => 'OUTGOING',
                ':participantId' => $userId, ':pending' => 'PENDING',
                ':venueOut' => 'VENUE_FEE', ':outgoing2' => 'OUTGOING',
                ':organizerId' => $userId, ':venueIn' => 'VENUE_FEE',
                ':incoming1' => 'INCOMING', ':ownerId' => $userId,
                ':venuePaid' => 'PAID', ':participantIn' => 'PARTICIPANT_FEE',
                ':incoming2' => 'INCOMING', ':hostId' => $userId,
                ':participantPaid' => 'PAID', ':completed' => 'COMPLETED',
            ]
        );
    }

    public function cancelParticipantPayment(string $eventId, string $userId): void
    {
        $event = $this->remote->eventDetails($eventId, $userId);
        $this->assertEventHasNotStarted($event);

        $registration = $this->registrations->findForUserAndEvent($userId, $eventId);

        if (!$registration instanceof EventRegistration || !$registration->isActive()) {
            throw new DomainException('You are not registered for this event.');
        }

        $row = $this->one(
            'SELECT * FROM `ParticipantPayment` WHERE `eventRegistrationId` = :id LIMIT 1',
            [':id' => (string) $registration->getEventRegistrationId()]
        );

        // A registration with no payment behind it - one made before fees
        // existed, or through the Discovery service - still has to be leavable.
        // There is nothing to refund, so the place is simply given back.
        if ($row === null) {
            $this->registrations->cancel($registration);

            return;
        }

        $this->refundParticipantRow($row, 'Participant cancelled before the event started.');
    }

    /** @return array<int,array<string,mixed>> */
    public function savedMethodsForUser(string $userId): array
    {
        $rows = $this->all(
            'SELECT * FROM `SavedPaymentMethod`
              WHERE `baseUserId` = :user
              ORDER BY `isDefault` DESC, `updatedAt` DESC',
            [':user' => $userId]
        );

        foreach ($rows as $index => $row) {
            $detail = $this->decodeJson($row['detailJson'] ?? null);
            $rows[$index]['detail'] = $detail;
            $rows[$index]['autofill'] = $this->autofillFromDetail(
                (string) $row['paymentMethod'],
                $detail
            );
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public function savePaymentMethod(
        string $userId,
        array $snapshot,
        string $label,
        bool $isDefault
    ): void {
        $label = trim($label);

        if (strlen($label) < 1 || strlen($label) > 100) {
            throw new DomainException('Enter a name for this saved method.');
        }

        $count = $this->one(
            'SELECT COUNT(*) AS `total` FROM `SavedPaymentMethod` WHERE `baseUserId` = :user',
            [':user' => $userId]
        );

        if ((int) ($count['total'] ?? 0) >= self::MAX_SAVED_METHODS) {
            throw new DomainException('You can save at most ' . self::MAX_SAVED_METHODS . ' payment methods.');
        }

        Database::transaction(function () use ($userId, $snapshot, $label, $isDefault, $count): void {
            $makeDefault = $isDefault || (int) ($count['total'] ?? 0) === 0;

            if ($makeDefault) {
                $this->execute(
                    'UPDATE `SavedPaymentMethod` SET `isDefault` = 0 WHERE `baseUserId` = :user',
                    [':user' => $userId]
                );
            }

            $this->execute(
                'INSERT INTO `SavedPaymentMethod`
                    (`savedPaymentMethodId`, `baseUserId`, `paymentMethod`, `label`,
                     `payerName`, `accountMask`, `providerLabel`, `detailJson`, `isDefault`)
                 VALUES (:id, :user, :method, :label, :payerName, :accountMask, :providerLabel, :detail, :isDefault)',
                [
                    ':id' => uuid(),
                    ':user' => $userId,
                    ':method' => (string) $snapshot['paymentMethod'],
                    ':label' => $label,
                    ':payerName' => (string) $snapshot['payerName'],
                    ':accountMask' => (string) $snapshot['accountMask'],
                    ':providerLabel' => (string) $snapshot['providerLabel'],
                    ':detail' => $this->encodeJson($snapshot['methodDetailJson'] ?? []),
                    ':isDefault' => $makeDefault ? 1 : 0,
                ]
            );
        });
    }

    public function deleteSavedMethod(string $userId, string $savedPaymentMethodId): void
    {
        $this->execute(
            'DELETE FROM `SavedPaymentMethod`
              WHERE `savedPaymentMethodId` = :id AND `baseUserId` = :user',
            [':id' => $savedPaymentMethodId, ':user' => $userId]
        );
    }

    public function setDefaultSavedMethod(string $userId, string $savedPaymentMethodId): void
    {
        Database::transaction(function () use ($userId, $savedPaymentMethodId): void {
            $row = $this->savedMethodRow($userId, $savedPaymentMethodId);

            if ($row === null) {
                throw new DomainException('That saved payment method is not available.');
            }

            $this->execute(
                'UPDATE `SavedPaymentMethod` SET `isDefault` = 0 WHERE `baseUserId` = :user',
                [':user' => $userId]
            );
            $this->execute(
                'UPDATE `SavedPaymentMethod` SET `isDefault` = 1
                  WHERE `savedPaymentMethodId` = :id AND `baseUserId` = :user',
                [':id' => $savedPaymentMethodId, ':user' => $userId]
            );
        });
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

        if (
            (string) ($event['status'] ?? '') !== 'COMPLETED'
            && !$this->eventHasEnded($event)
        ) {
            throw new DomainException('Participant fees can only be paid out after the event has ended.');
        }

        $sum = $this->one(
            'SELECT COALESCE(SUM(`amount`), 0) AS `amount`
               FROM `ParticipantPayment`
              WHERE `eventId` = :event AND `paymentStatus` = :status',
            [':event' => $eventId, ':status' => 'PAID']
        );
        $amount = round((float) ($sum['amount'] ?? 0), 2);

        return [
            'eventId' => $eventId,
            'organizerId' => $organizerId,
            'amount' => $amount,
            'status' => 'PAID',
        ];
    }

    /** @param array<string,mixed> $row */
    private function refundParticipantRow(array $row, string $reason): void
    {
        Database::transaction(function () use ($row): void {
            $this->execute(
                'UPDATE `ParticipantPayment`
                    SET `paymentStatus` = :status
                  WHERE `participantPaymentId` = :id
                    AND `paymentStatus` <> :refunded',
                [
                    ':status' => 'REFUNDED', ':id' => $row['participantPaymentId'],
                    ':refunded' => 'REFUNDED',
                ]
            );

            // The registration is Discovery & Event Matchmaking's row, so it
            // is released through that module's mapper rather than updated
            // here. Already-cancelled rows are left alone.
            $registration = $this->registrations->find((string) $row['eventRegistrationId']);

            if ($registration instanceof EventRegistration && $registration->isActive()) {
                $this->registrations->cancel($registration);
            }
        });
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
                        ':method' => 'not_selected', ':status' => 'PENDING',
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

    /** @param array<string,mixed> $event */
    private function eventHasEnded(array $event): bool
    {
        $endsAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) ($event['eventDate'] ?? '') . ' ' . (string) ($event['endTime'] ?? '')
        );

        return $endsAt === false || $endsAt <= new DateTimeImmutable();
    }

    /** @return array<string,mixed>|null */
    private function savedMethodRow(string $userId, string $savedPaymentMethodId): ?array
    {
        return $this->one(
            'SELECT * FROM `SavedPaymentMethod`
              WHERE `savedPaymentMethodId` = :id AND `baseUserId` = :user LIMIT 1',
            [':id' => $savedPaymentMethodId, ':user' => $userId]
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $saved
     * @return array<string,mixed>
     */
    private function mergeSavedInput(array $input, array $saved): array
    {
        $autofill = $this->autofillFromDetail(
            (string) $saved['paymentMethod'],
            $this->decodeJson($saved['detailJson'] ?? null)
        );

        foreach ($autofill as $key => $value) {
            $current = trim((string) ($input[$key] ?? ''));

            if ($current === '' && $value !== '') {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    /**
     * @param array<string,mixed> $detail
     * @return array<string,string>
     */
    private function autofillFromDetail(string $method, array $detail): array
    {
        if ($method === 'card') {
            $last4 = (string) ($detail['last4'] ?? '');

            return [
                'holderName' => (string) ($detail['holderName'] ?? ''),
                'cardNumber' => $this->demoDigitsEndingIn($last4, 16),
                'expiryMonth' => (string) ($detail['expiryMonth'] ?? ''),
                'expiryYear' => (string) ($detail['expiryYear'] ?? ''),
            ];
        }

        if ($method === 'fpx') {
            $last4 = (string) ($detail['accountLast4'] ?? '');

            return [
                'bankName' => (string) ($detail['bankName'] ?? ''),
                'accountHolder' => (string) ($detail['accountHolder'] ?? ''),
                'accountNumber' => $this->demoDigitsEndingIn($last4, 10),
            ];
        }

        return [
            'walletProvider' => (string) ($detail['walletProvider'] ?? ''),
            'walletAccount' => (string) ($detail['walletAccount'] ?? ''),
        ];
    }

    private function demoDigitsEndingIn(string $last4, int $length): string
    {
        $last4 = substr(preg_replace('/\D+/', '', $last4) ?? '', -4);

        if (strlen($last4) !== 4) {
            return '';
        }

        return str_pad($last4, $length, '4', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,string>
     */
    private function snapshotParams(array $snapshot): array
    {
        return [
            ':method' => (string) $snapshot['paymentMethod'],
            ':payerName' => (string) $snapshot['payerName'],
            ':accountMask' => (string) $snapshot['accountMask'],
            ':providerLabel' => (string) $snapshot['providerLabel'],
            ':detail' => $this->encodeJson($snapshot['methodDetailJson'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $encoded = json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
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
