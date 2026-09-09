<?php
// Reads and writes password reset tickets. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Model;

use App\Core\DataMapper;
use App\Core\Entity;
use DateTimeImmutable;
use InvalidArgumentException;

final class PasswordResetMapper extends DataMapper
{
    protected function table(): string
    {
        return 'PasswordReset';
    }

    protected function primaryKey(): string
    {
        return 'passwordResetId';
    }

    protected function columns(): array
    {
        return ['passwordResetId', 'baseUserId', 'tokenHash', 'requestedAt', 'expiresAt', 'usedAt', 'requestIp'];
    }

    public function insert(Entity $entity): void
    {
        if (!$entity instanceof PasswordResetToken) {
            throw new InvalidArgumentException('PasswordResetMapper can only write a PasswordResetToken.');
        }

        $this->execute(
            'INSERT INTO `PasswordReset`
                 (`passwordResetId`, `baseUserId`, `tokenHash`, `requestedAt`, `expiresAt`, `requestIp`)
             VALUES (:id, :user, :hash, NOW(), :expires, :ip)',
            [
                ':id'      => $entity->getPasswordResetId(),
                ':user'    => $entity->getBaseUserId(),
                ':hash'    => $entity->getTokenHash(),
                ':expires' => $entity->getExpiresAt()->format('Y-m-d H:i:s'),
                ':ip'      => $entity->getRequestIp(),
            ]
        );
    }

    /**
     * Looked up by the hash of the presented token, so the raw token is never
     * compared in SQL and never appears in a query log.
     */
    public function findByTokenHash(string $tokenHash): ?PasswordResetToken
    {
        $row = $this->selectOne(
            'SELECT * FROM `PasswordReset` WHERE `tokenHash` = :hash LIMIT 1',
            [':hash' => $tokenHash]
        );

        return $row === null ? null : $this->toEntity($row);
    }

    /** Marks one ticket redeemed. The WHERE clause is what makes it single use. */
    public function markUsed(string $passwordResetId): bool
    {
        return $this->execute(
            'UPDATE `PasswordReset` SET `usedAt` = NOW() WHERE `passwordResetId` = :id AND `usedAt` IS NULL',
            [':id' => $passwordResetId]
        ) === 1;
    }

    /**
     * Called when a reset completes. Any other ticket outstanding for that
     * account dies with it, so an attacker who quietly requested one earlier
     * cannot use it after the real owner has recovered the account.
     */
    public function invalidateAllFor(string $baseUserId): void
    {
        $this->execute(
            'UPDATE `PasswordReset` SET `usedAt` = NOW() WHERE `baseUserId` = :id AND `usedAt` IS NULL',
            [':id' => $baseUserId]
        );
    }

    /** Rate limits the request form: how many tickets this account asked for recently. */
    public function countRequestedSince(string $baseUserId, DateTimeImmutable $since): int
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM `PasswordReset` WHERE `baseUserId` = :id AND `requestedAt` >= :since',
            [':id' => $baseUserId, ':since' => $since->format('Y-m-d H:i:s')]
        );

        return (int) ($row['total'] ?? 0);
    }

    protected function toEntity(array $row): PasswordResetToken
    {
        return new PasswordResetToken(
            (string) $row['passwordResetId'],
            (string) $row['baseUserId'],
            (string) $row['tokenHash'],
            $this->toDateTime($row['expiresAt']) ?? new DateTimeImmutable('-1 second'),
            $this->toDateTime($row['requestedAt'] ?? null),
            $this->toDateTime($row['usedAt'] ?? null),
            $row['requestIp'] !== null ? (string) $row['requestIp'] : null
        );
    }

    protected function toRow(Entity $entity): array
    {
        throw new InvalidArgumentException('Reset tickets are written through insert() only.');
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
