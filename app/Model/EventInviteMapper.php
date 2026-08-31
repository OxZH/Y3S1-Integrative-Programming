<?php
// Invite link persistence. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Model;

use App\Core\DataMapper;
use App\Core\Entity;
use DateTimeImmutable;
use InvalidArgumentException;

final class EventInviteMapper extends DataMapper
{
    protected function table(): string
    {
        return 'EventInvite';
    }

    protected function primaryKey(): string
    {
        return 'eventInviteId';
    }

    protected function columns(): array
    {
        return [
            'eventInviteId', 'eventId', 'token', 'createdBy',
            'createdAt', 'expiresAt', 'maxUses', 'useCount', 'revoked',
        ];
    }

    protected function toEntity(array $row): Entity
    {
        return new EventInvite(
            (string) $row['eventInviteId'],
            (string) $row['eventId'],
            (string) $row['token'],
            (string) $row['createdBy'],
            new DateTimeImmutable((string) $row['createdAt']),
            $row['expiresAt'] !== null ? new DateTimeImmutable((string) $row['expiresAt']) : null,
            $row['maxUses'] !== null ? (int) $row['maxUses'] : null,
            (int) $row['useCount'],
            (bool) $row['revoked']
        );
    }

    protected function toRow(Entity $entity): array
    {
        if (!$entity instanceof EventInvite) {
            throw new InvalidArgumentException('EventInviteMapper can only persist an EventInvite.');
        }

        return [
            'eventInviteId' => $entity->getEventInviteId(),
            'eventId'       => $entity->getEventId(),
            'token'         => $entity->getToken(),
            'createdBy'     => $entity->getCreatedBy(),
            'createdAt'     => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
            'expiresAt'     => $entity->getExpiresAt()?->format('Y-m-d H:i:s'),
            'maxUses'       => $entity->getMaxUses(),
            'useCount'      => $entity->getUseCount(),
            'revoked'       => $entity->isRevoked() ? 1 : 0,
        ];
    }

    public function findByToken(string $token): ?EventInvite
    {
        $row = $this->selectOne('SELECT * FROM `EventInvite` WHERE `token` = :token LIMIT 1', [':token' => $token]);

        if ($row === null) {
            return null;
        }

        /** @var EventInvite $invite */
        $invite = $this->register($this->toEntity($row));

        return $invite;
    }

    /** @return EventInvite[] */
    public function findByEvent(string $eventId): array
    {
        /** @var EventInvite[] $invites */
        $invites = $this->findBy(['eventId' => $eventId], 'createdAt', 'DESC');

        return $invites;
    }

    /**
     * One UPDATE rather than read-modify-write: two people opening the same
     * single use link at once would both read useCount = 0 and both get in. The
     * WHERE re-checks the cap, so the loser affects zero rows.
     */
    public function recordUse(EventInvite $invite): bool
    {
        $affected = $this->execute(
            'UPDATE `EventInvite`
                SET `useCount` = `useCount` + 1
              WHERE `eventInviteId` = :id
                AND `revoked` = 0
                AND (`expiresAt` IS NULL OR `expiresAt` > NOW())
                AND (`maxUses` IS NULL OR `useCount` < `maxUses`)',
            [':id' => $invite->getEventInviteId()]
        );

        if ($affected === 1) {
            $invite->recordUse();

            return true;
        }

        return false;
    }
}
