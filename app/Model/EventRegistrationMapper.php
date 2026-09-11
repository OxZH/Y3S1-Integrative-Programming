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
     * Join an event. Locks the Event row (FOR UPDATE) before counting, so two
     * users can't both grab the last spot at the same time. A cancelled row is
     * reused instead of inserting a new one, since (userId, eventId) is unique.
     *
     * @throws DomainException event is full, or user already joined
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
                // another request inserted first
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

    /** @return EventRegistration[] newest first */
    public function findByUser(string $userId): array
    {
        /** @var EventRegistration[] $registrations */
        $registrations = $this->findBy(['userId' => $userId], 'registerTime', 'DESC');

        return $registrations;
    }

    /**
     * One page of players for an event (CONFIRMED and ATTENDED only), in join order.
     * @return EventRegistration[]
     */
    public function findActiveForEvent(string $eventId, int $limit, int $offset): array
    {
        // LIMIT/OFFSET can't be bound params, so cast and clamp them first
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
