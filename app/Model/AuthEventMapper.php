<?php
// Reads and writes the authentication audit trail. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Model;

use App\AuthEventType;
use App\Core\DataMapper;
use App\Core\Entity;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Append only. There is no update() and no delete(): an audit trail that the
 * application can edit is not an audit trail. Rows age out by a scheduled
 * database job, never through this class.
 */
final class AuthEventMapper extends DataMapper
{
    private const SELECT = 'SELECT l.authEventId, l.baseUserId, l.eventType, l.emailTried,
                                   l.succeeded, l.ipAddress, l.userAgent, l.detail, l.occurredAt,
                                   b.username
                              FROM `AuthEventLog` l
                              LEFT JOIN `BaseUser` b ON b.baseUserId = l.baseUserId';

    protected function table(): string
    {
        return 'AuthEventLog';
    }

    protected function primaryKey(): string
    {
        return 'authEventId';
    }

    protected function columns(): array
    {
        return [
            'authEventId', 'baseUserId', 'eventType', 'emailTried',
            'succeeded', 'ipAddress', 'userAgent', 'detail', 'occurredAt',
        ];
    }

    public function insert(Entity $entity): void
    {
        if (!$entity instanceof AuthEvent) {
            throw new InvalidArgumentException('AuthEventMapper can only write an AuthEvent.');
        }

        $this->execute(
            'INSERT INTO `AuthEventLog`
                 (`authEventId`, `baseUserId`, `eventType`, `emailTried`,
                  `succeeded`, `ipAddress`, `userAgent`, `detail`, `occurredAt`)
             VALUES (:id, :user, :type, :email, :ok, :ip, :agent, :detail, NOW())',
            [
                ':id'     => $entity->getAuthEventId(),
                ':user'   => $entity->getBaseUserId(),
                ':type'   => $entity->getEventType()->value,
                ':email'  => $entity->getEmailTried(),
                ':ok'     => $entity->succeeded() ? 1 : 0,
                ':ip'     => $entity->getIpAddress(),
                ':agent'  => $entity->getUserAgent(),
                ':detail' => $entity->getDetail(),
            ]
        );
    }

    public function update(Entity $entity): void
    {
        throw new InvalidArgumentException('The audit trail is append only.');
    }

    public function delete(string $id): void
    {
        throw new InvalidArgumentException('The audit trail is append only.');
    }

    /** @return AuthEvent[] */
    public function recentForUser(string $baseUserId, int $limit = 20): array
    {
        /** @var AuthEvent[] $events */
        $events = $this->hydrateAll($this->select(
            self::SELECT . ' WHERE l.baseUserId = :id ORDER BY l.occurredAt DESC LIMIT ' . $this->safeLimit($limit),
            [':id' => $baseUserId]
        ));

        return $events;
    }

    /** @return AuthEvent[] */
    public function recent(int $limit = 100): array
    {
        /** @var AuthEvent[] $events */
        $events = $this->hydrateAll($this->select(
            self::SELECT . ' ORDER BY l.occurredAt DESC LIMIT ' . $this->safeLimit($limit)
        ));

        return $events;
    }

    /** @return AuthEvent[] */
    public function recentOfType(AuthEventType $type, int $limit = 100): array
    {
        /** @var AuthEvent[] $events */
        $events = $this->hydrateAll($this->select(
            self::SELECT . ' WHERE l.eventType = :type ORDER BY l.occurredAt DESC LIMIT ' . $this->safeLimit($limit),
            [':type' => $type->value]
        ));

        return $events;
    }

    /** Failed sign-ins against one account since a moment - drives the lockout view. */
    public function countFailuresSince(string $baseUserId, DateTimeImmutable $since): int
    {
        $row = $this->selectOne(
            "SELECT COUNT(*) AS total FROM `AuthEventLog`
              WHERE `baseUserId` = :id AND `eventType` = 'LOGIN_FAILED' AND `occurredAt` >= :since",
            [':id' => $baseUserId, ':since' => $since->format('Y-m-d H:i:s')]
        );

        return (int) ($row['total'] ?? 0);
    }

    protected function toEntity(array $row): Entity
    {
        return new AuthEvent(
            (string) $row['authEventId'],
            $row['baseUserId'] !== null ? (string) $row['baseUserId'] : null,
            AuthEventType::from((string) $row['eventType']),
            (bool) $row['succeeded'],
            $row['emailTried'] !== null ? (string) $row['emailTried'] : null,
            $row['ipAddress'] !== null ? (string) $row['ipAddress'] : null,
            $row['userAgent'] !== null ? (string) $row['userAgent'] : null,
            $row['detail'] !== null ? (string) $row['detail'] : null,
            $this->toDateTime($row['occurredAt'] ?? null),
            $row['username'] !== null ? (string) $row['username'] : null
        );
    }

    protected function toRow(Entity $entity): array
    {
        throw new InvalidArgumentException('The audit trail is written through insert() only.');
    }

    // LIMIT takes no placeholder, so the value is forced to an integer in a
    // known range rather than interpolated as given.
    private function safeLimit(int $limit): string
    {
        return (string) max(1, min(500, $limit));
    }

    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $parsed === false ? null : $parsed;
    }
}
