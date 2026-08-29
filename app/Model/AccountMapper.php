<?php
// Reads accounts belonging to the User Authentication module. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Model;

use App\Core\DataMapper;
use App\Core\Entity;
use RuntimeException;

/**
 * Read only. This module never creates or edits an account, it only resolves the
 * references Facility and Event hold.
 *
 * find() is overridden because the account tables use class table inheritance:
 * the shared columns are on BaseUser and each role adds its own table.
 */
final class AccountMapper extends DataMapper
{
    private const SELECT = 'SELECT b.baseUserId, b.username, b.email, b.contactNumber,
                                   b.userType, b.accountStatus, o.bankName, o.businessRegNum
                              FROM `BaseUser` b
                              LEFT JOIN `FacilityOwner` o ON o.baseUserId = b.baseUserId';

    protected function table(): string
    {
        return 'BaseUser';
    }

    protected function primaryKey(): string
    {
        return 'baseUserId';
    }

    protected function columns(): array
    {
        return ['baseUserId', 'username', 'email', 'contactNumber', 'userType', 'accountStatus'];
    }

    public function find(string $id): ?Entity
    {
        if (isset($this->identityMap[$id])) {
            return $this->identityMap[$id];
        }

        $row = $this->selectOne(self::SELECT . ' WHERE b.baseUserId = :pk LIMIT 1', [':pk' => $id]);

        return $row === null ? null : $this->register($this->toEntity($row));
    }

    public function findAccount(string $id): ?Account
    {
        $found = $this->find($id);

        return $found instanceof Account ? $found : null;
    }

    /** @return Account[] */
    public function findByType(string $userType): array
    {
        /** @var Account[] $accounts */
        $accounts = $this->hydrateAll($this->select(
            self::SELECT . ' WHERE b.userType = :type ORDER BY b.username',
            [':type' => $userType]
        ));

        return $accounts;
    }

    protected function toEntity(array $row): Entity
    {
        return new Account(
            (string) $row['baseUserId'],
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['contactNumber'],
            (string) $row['userType'],
            (string) $row['accountStatus'],
            $row['bankName'] !== null ? (string) $row['bankName'] : null,
            $row['businessRegNum'] !== null ? (string) $row['businessRegNum'] : null
        );
    }

    protected function toRow(Entity $entity): array
    {
        throw new RuntimeException('Accounts belong to the User Authentication module.');
    }

    public function insert(Entity $entity): void
    {
        $this->toRow($entity);
    }

    public function update(Entity $entity): void
    {
        $this->toRow($entity);
    }
}
