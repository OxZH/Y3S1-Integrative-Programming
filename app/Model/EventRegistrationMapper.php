<?php
// Participation persistence and the capacity guard. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Model;

use App\Core\Database;
use App\Core\DataMapper;
use App\Core\Entity;
use App\RegistrationStatus;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDOException;

final class EventRegistrationMapper extends DataMapper
{
    private ?AccountMapper $accounts = null;
    private ?EventMapper $events = null;
    private ?EventInviteMapper $invites = null;

    protected function table(): string
    {
        return 'EventRegistration';
    }

    protected function primaryKey(): string
    {
        return 'eventRegistrationId';
    }

    protected function columns(): array
    {
        return ['eventRegistrationId', 'userId', 'eventId', 'status', 'registerTime', 'eventInviteId'];
    }

    protected function toEntity(array $row): Entity
    {
        $registration = new EventRegistration(
            (string) $row['eventRegistrationId'],
            (string) $row['userId'],
            (string) $row['eventId'],
            RegistrationStatus::from((string) $row['status']),
            new DateTimeImmutable((string) $row['registerTime']),
            $row['eventInviteId'] !== null ? (string) $row['eventInviteId'] : null
        );

        $userId       = (string) $row['userId'];
        $eventId      = (string) $row['eventId'];
        $eventInviteId = $row['eventInviteId'] !== null ? (string) $row['eventInviteId'] : null;

        $registration->setLoader('user', fn () => $this->accounts()->findAccount($userId));
        $registration->setLoader('event', fn () => $this->events()->find($eventId));
        $registration->setLoader(
            'invite',
            fn () => $eventInviteId === null ? null : $this->invites()->find($eventInviteId)
        );

        return $registration;
    }

    protected function toRow(Entity $entity): array
    {
        if (!$entity instanceof EventRegistration) {
            throw new InvalidArgumentException('EventRegistrationMapper can only persist an EventRegistration.');
        }

        return [
            'eventRegistrationId' => $entity->getEventRegistrationId(),
            'userId'              => $entity->getUserId(),
            'eventId'             => $entity->getEventId(),
            'status'              => $entity->getStatus()->value,
            'registerTime'        => $entity->getRegisterTime()->format('Y-m-d H:i:s'),
            'eventInviteId'       => $entity->getEventInviteId(),
        ];
    }

    /**
     * The join path. A plain "count then insert" is still two separate steps -
     * two concurrent requests can both read 7 of 8 before either writes, and both
     * insert, leaving 9 of 8. `SELECT ... FOR UPDATE` locks the event row first,
     * so a second request for the same event has to wait for the first to finish;
     * only then does it see the up to date count. This is the same shape as
     * EventInviteMapper::recordUse() (recheck the cap inside the write itself),
     * applied here as a row lock rather than a conditional UPDATE because the
     * count lives in a second table, not in a column that can be decremented
     * directly.
     *
     * uq_EventRegistration_user_event covers the (userId, eventId) pair
     * regardless of status, so a user who left and wants back in cannot get a
     * second row - the existing one (CANCELLED, NO_SHOW, ...) is reactivated
     * instead of inserting, or the unique constraint would refuse the insert
     * and this would wrongly read as "already joined" when they are not
     * currently an active participant.
     *
     * @throws DomainException the event is full, or this user already joined
     */
    public function registerIfSpaceAvailable(
        string $eventId,
        string $userId,
        int $maxParticipants,
        ?string $eventInviteId = null
    ): EventRegistration {
        return Database::transaction(function () use ($eventId, $userId, $maxParticipants, $eventInviteId): EventRegistration {
            $lock = $this->pdo->prepare('SELECT `eventId` FROM `Event` WHERE `eventId` = :id FOR UPDATE');
            $lock->execute([':id' => $eventId]);

            if ($lock->fetch() === false) {
                throw new DomainException('That event no longer exists.');
            }

            $existing = $this->findForUserAndEvent($userId, $eventId);

            if ($existing instanceof EventRegistration && $existing->isActive()) {
                throw new DomainException('You have already joined this event.');
            }

            if ($this->countActive($eventId) >= $maxParticipants) {
                throw new DomainException('This event is already full.');
            }

            if ($existing instanceof EventRegistration) {
                $existing->rejoin($eventInviteId);
                $this->update($existing);

                return $existing;
            }

            $registration = new EventRegistration(
                uuid(),
                $userId,
                $eventId,
                RegistrationStatus::CONFIRMED,
                new DateTimeImmutable(),
                $eventInviteId
            );

            try {
                $this->insert($registration);
            } catch (PDOException $e) {
                // A concurrent request won the race and inserted first.
                if ((string) $e->getCode() === '23000') {
                    throw new DomainException('You have already joined this event.');
                }

                throw $e;
            }

            return $registration;
        });
    }

    public function cancel(EventRegistration $registration): void
    {
        $registration->cancel();
        $this->update($registration);
    }

    public function countActive(string $eventId): int
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM `EventRegistration`
              WHERE `eventId` = :eventId AND `status` IN (:confirmed, :attended)',
            [':eventId' => $eventId, ':confirmed' => 'CONFIRMED', ':attended' => 'ATTENDED']
        );

        return (int) ($row['total'] ?? 0);
    }

    public function findForUserAndEvent(string $userId, string $eventId): ?EventRegistration
    {
        $row = $this->selectOne(
            'SELECT * FROM `EventRegistration` WHERE `userId` = :userId AND `eventId` = :eventId LIMIT 1',
            [':userId' => $userId, ':eventId' => $eventId]
        );

        /** @var EventRegistration|null $registration */
        $registration = $row === null ? null : $this->register($this->toEntity($row));

        return $registration;
    }

    /** @return EventRegistration[] most recent first - this module's answer for a "participation history" */
    public function findByUser(string $userId): array
    {
        /** @var EventRegistration[] $registrations */
        $registrations = $this->findBy(['userId' => $userId], 'registerTime', 'DESC');

        return $registrations;
    }

    /**
     * One page of the players in an event, in the order they signed up, so the
     * team sheet reads the way the queue formed.
     *
     * Only CONFIRMED and ATTENDED appear: somebody who left is not a player, and
     * the list is a team sheet rather than an audit trail of who changed their
     * mind.
     *
     * @return EventRegistration[]
     */
    public function findActiveForEvent(string $eventId, int $limit, int $offset): array
    {
        // LIMIT and OFFSET cannot be bound parameters, so both are forced into
        // range as integers rather than interpolated as given.
        $safeLimit  = max(1, min(50, $limit));
        $safeOffset = max(0, $offset);

        $rows = $this->select(
            'SELECT * FROM `EventRegistration`
              WHERE `eventId` = :eventId AND `status` IN (:confirmed, :attended)
              ORDER BY `registerTime` ASC
              LIMIT ' . $safeLimit . ' OFFSET ' . $safeOffset,
            [':eventId' => $eventId, ':confirmed' => 'CONFIRMED', ':attended' => 'ATTENDED']
        );

        $registrations = [];

        foreach ($rows as $row) {
            /** @var EventRegistration $registration */
            $registration    = $this->register($this->toEntity($row));
            $registrations[] = $registration;
        }

        return $registrations;
    }

    private function accounts(): AccountMapper
    {
        return $this->accounts ??= new AccountMapper($this->pdo);
    }

    private function events(): EventMapper
    {
        return $this->events ??= new EventMapper($this->pdo);
    }

    private function invites(): EventInviteMapper
    {
        return $this->invites ??= new EventInviteMapper($this->pdo);
    }
}
