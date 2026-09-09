<?php
// Maps the account hierarchy to the BaseUser + role tables. Author: Ivan Lim Tze Yang

namespace App\Model;

use App\AccountStatus;
use App\Core\Database;
use App\Core\DataMapper;
use App\Core\Entity;
use App\UserType;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Class table inheritance: one SELECT joins BaseUser to all three role tables
 * and the discriminator decides which subclass to build, so reading an account
 * never costs a second query and never needs to know the role in advance.
 *
 * Writes go through a transaction, because an account is two rows - the shared
 * BaseUser row and the role row - and a half-created account with no role row
 * would be an account that can sign in and then fail on every page.
 *
 * The password hash is never selected into an entity. credentialsForEmail() is
 * the only method that reads the column, it returns a plain array, and only the
 * authentication service calls it.
 */
final class AccountMapper extends DataMapper
{
    private const SELECT = 'SELECT b.baseUserId, b.email, b.username, b.contactNumber,
                                   b.registerTime, b.userType, b.accountStatus,
                                   b.lastLoginAt, b.passwordChangedAt,
                                   u.favoriteSport, u.location, u.profilePicURL,
                                   u.birthDate, u.latitude, u.longitude,
                                   o.bankName, o.bankAccountNum, o.businessRegNum,
                                   a.adminId
                              FROM `BaseUser` b
                              LEFT JOIN `User`          u ON u.baseUserId = b.baseUserId
                              LEFT JOIN `FacilityOwner` o ON o.baseUserId = b.baseUserId
                              LEFT JOIN `Admin`         a ON a.baseUserId = b.baseUserId';

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
        return [
            'baseUserId', 'email', 'password', 'username', 'contactNumber',
            'registerTime', 'userType', 'accountStatus',
            'failedLoginAttempts', 'lockedUntil', 'lastLoginAt', 'passwordChangedAt',
        ];
    }

    // ---------------------------------------------------------------- reading

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

    public function findByEmail(string $email): ?Account
    {
        $row = $this->selectOne(self::SELECT . ' WHERE b.email = :email LIMIT 1', [':email' => $email]);

        if ($row === null) {
            return null;
        }

        $account = $this->register($this->toEntity($row));

        return $account instanceof Account ? $account : null;
    }

    /**
     * Module 1 calls this with a plain string. Both spellings are accepted so
     * that call site did not have to change.
     *
     * @return Account[]
     */
    public function findByType(UserType|string $userType): array
    {
        $value = $userType instanceof UserType ? $userType->value : $userType;

        /** @var Account[] $accounts */
        $accounts = $this->hydrateAll($this->select(
            self::SELECT . ' WHERE b.userType = :type ORDER BY b.username',
            [':type' => $value]
        ));

        return $accounts;
    }

    /** @return Account[] */
    public function findAll(): array
    {
        /** @var Account[] $accounts */
        $accounts = $this->hydrateAll($this->select(self::SELECT . ' ORDER BY b.registerTime DESC'));

        return $accounts;
    }

    public function emailTaken(string $email, ?string $exceptId = null): bool
    {
        return $this->existsWith('email', $email, $exceptId);
    }

    public function usernameTaken(string $username, ?string $exceptId = null): bool
    {
        return $this->existsWith('username', $username, $exceptId);
    }

    // ------------------------------------------------------------ credentials

    /**
     * The only read of the password column. Returns an array rather than an
     * entity on purpose: there is no object holding a hash, so no view, no
     * json_encode and no var_dump can ever print one.
     *
     * @return array{baseUserId:string,password:string,accountStatus:string,failedLoginAttempts:int,lockedUntil:?string}|null
     */
    public function credentialsForEmail(string $email): ?array
    {
        $row = $this->selectOne(
            'SELECT `baseUserId`, `password`, `accountStatus`, `failedLoginAttempts`, `lockedUntil`
               FROM `BaseUser` WHERE `email` = :email LIMIT 1',
            [':email' => $email]
        );

        if ($row === null) {
            return null;
        }

        return [
            'baseUserId'          => (string) $row['baseUserId'],
            'password'            => (string) $row['password'],
            'accountStatus'       => (string) $row['accountStatus'],
            'failedLoginAttempts' => (int) $row['failedLoginAttempts'],
            'lockedUntil'         => $row['lockedUntil'] !== null ? (string) $row['lockedUntil'] : null,
        ];
    }

    public function passwordHashFor(string $baseUserId): ?string
    {
        $row = $this->selectOne(
            'SELECT `password` FROM `BaseUser` WHERE `baseUserId` = :pk LIMIT 1',
            [':pk' => $baseUserId]
        );

        return $row === null ? null : (string) $row['password'];
    }

    public function storePasswordHash(string $baseUserId, string $hash): void
    {
        $this->execute(
            'UPDATE `BaseUser`
                SET `password` = :hash,
                    `passwordChangedAt` = NOW(),
                    `failedLoginAttempts` = 0,
                    `lockedUntil` = NULL
              WHERE `baseUserId` = :pk',
            [':hash' => $hash, ':pk' => $baseUserId]
        );
    }

    /** @return int the new consecutive-failure count */
    public function recordFailedLogin(string $baseUserId): int
    {
        $this->execute(
            'UPDATE `BaseUser` SET `failedLoginAttempts` = `failedLoginAttempts` + 1 WHERE `baseUserId` = :pk',
            [':pk' => $baseUserId]
        );

        $row = $this->selectOne(
            'SELECT `failedLoginAttempts` FROM `BaseUser` WHERE `baseUserId` = :pk LIMIT 1',
            [':pk' => $baseUserId]
        );

        return (int) ($row['failedLoginAttempts'] ?? 0);
    }

    public function lockUntil(string $baseUserId, DateTimeImmutable $until): void
    {
        $this->execute(
            'UPDATE `BaseUser` SET `lockedUntil` = :until WHERE `baseUserId` = :pk',
            [':until' => $until->format('Y-m-d H:i:s'), ':pk' => $baseUserId]
        );
    }

    public function clearLoginFailures(string $baseUserId): void
    {
        $this->execute(
            'UPDATE `BaseUser`
                SET `failedLoginAttempts` = 0, `lockedUntil` = NULL, `lastLoginAt` = NOW()
              WHERE `baseUserId` = :pk',
            [':pk' => $baseUserId]
        );
    }

    /** The previous sign-in, read before the current one overwrites it. */
    public function previousLoginAt(string $baseUserId): ?string
    {
        $row = $this->selectOne(
            'SELECT `lastLoginAt` FROM `BaseUser` WHERE `baseUserId` = :pk LIMIT 1',
            [':pk' => $baseUserId]
        );

        return $row === null || $row['lastLoginAt'] === null ? null : (string) $row['lastLoginAt'];
    }

    // ---------------------------------------------------------------- writing

    /** Only used at registration, where the plaintext has just been hashed. */
    public function insertWithPassword(Account $account, string $passwordHash): void
    {
        Database::transaction(function () use ($account, $passwordHash): void {
            $this->execute(
                'INSERT INTO `BaseUser`
                     (`baseUserId`, `email`, `password`, `username`, `contactNumber`,
                      `registerTime`, `userType`, `accountStatus`, `passwordChangedAt`)
                 VALUES (:id, :email, :password, :username, :contact, NOW(), :type, :status, NOW())',
                [
                    ':id'       => $account->getBaseUserId(),
                    ':email'    => $account->getEmail(),
                    ':password' => $passwordHash,
                    ':username' => $account->getUsername(),
                    ':contact'  => $account->getContactNumber(),
                    ':type'     => $account->getUserType()->value,
                    ':status'   => $account->getAccountStatus()->value,
                ]
            );

            $this->insertRoleRow($account);
            $this->register($account);
        });
    }

    public function update(Entity $entity): void
    {
        if (!$entity instanceof Account) {
            throw new InvalidArgumentException('AccountMapper can only save an Account.');
        }

        Database::transaction(function () use ($entity): void {
            $this->execute(
                'UPDATE `BaseUser`
                    SET `email` = :email, `username` = :username,
                        `contactNumber` = :contact, `accountStatus` = :status
                  WHERE `baseUserId` = :pk',
                [
                    ':email'    => $entity->getEmail(),
                    ':username' => $entity->getUsername(),
                    ':contact'  => $entity->getContactNumber(),
                    ':status'   => $entity->getAccountStatus()->value,
                    ':pk'       => $entity->getBaseUserId(),
                ]
            );

            $this->updateRoleRow($entity);
        });
    }

    public function insert(Entity $entity): void
    {
        // An account without a password hash would be an account nobody can sign
        // in to and anybody can reset. Registration must go through
        // insertWithPassword().
        throw new InvalidArgumentException('Use insertWithPassword() to create an account.');
    }

    public function setStatus(string $baseUserId, AccountStatus $status): void
    {
        $this->execute(
            'UPDATE `BaseUser` SET `accountStatus` = :status WHERE `baseUserId` = :pk',
            [':status' => $status->value, ':pk' => $baseUserId]
        );

        unset($this->identityMap[$baseUserId]);
    }

    // ------------------------------------------------------------- hydration

    protected function toEntity(array $row): Entity
    {
        $type   = UserType::from((string) $row['userType']);
        $status = AccountStatus::from((string) $row['accountStatus']);

        $shared = [
            (string) $row['baseUserId'],
            (string) $row['email'],
            (string) $row['username'],
            (string) $row['contactNumber'],
            $status,
            $this->toDate($row['registerTime'] ?? null, 'Y-m-d H:i:s'),
            $this->toDate($row['lastLoginAt'] ?? null, 'Y-m-d H:i:s'),
            $this->toDate($row['passwordChangedAt'] ?? null, 'Y-m-d H:i:s'),
        ];

        return match ($type) {
            UserType::USER => new User(
                ...$shared,
                favoriteSport: $this->toNullableString($row['favoriteSport'] ?? null),
                location:      $this->toNullableString($row['location'] ?? null),
                profilePicURL: $this->toNullableString($row['profilePicURL'] ?? null),
                birthDate:     $this->toDate($row['birthDate'] ?? null, 'Y-m-d'),
                latitude:      isset($row['latitude'])  && $row['latitude']  !== null ? (float) $row['latitude']  : null,
                longitude:     isset($row['longitude']) && $row['longitude'] !== null ? (float) $row['longitude'] : null
            ),
            UserType::FACILITY_OWNER => new FacilityOwner(
                ...$shared,
                bankName:       (string) ($row['bankName'] ?? ''),
                bankAccountNum: (string) ($row['bankAccountNum'] ?? ''),
                businessRegNum: (string) ($row['businessRegNum'] ?? '')
            ),
            UserType::ADMIN => new Admin(
                ...$shared,
                adminId: (string) ($row['adminId'] ?? '')
            ),
        };
    }

    protected function toRow(Entity $entity): array
    {
        if (!$entity instanceof Account) {
            throw new InvalidArgumentException('AccountMapper can only save an Account.');
        }

        return [
            'baseUserId'    => $entity->getBaseUserId(),
            'email'         => $entity->getEmail(),
            'username'      => $entity->getUsername(),
            'contactNumber' => $entity->getContactNumber(),
            'userType'      => $entity->getUserType()->value,
            'accountStatus' => $entity->getAccountStatus()->value,
        ];
    }

    // --------------------------------------------------------------- private

    private function insertRoleRow(Account $account): void
    {
        if ($account instanceof User) {
            $this->execute(
                'INSERT INTO `User`
                     (`baseUserId`, `favoriteSport`, `location`, `profilePicURL`, `birthDate`, `latitude`, `longitude`)
                 VALUES (:id, :sport, :location, :pic, :birth, :lat, :lng)',
                [
                    ':id'       => $account->getBaseUserId(),
                    ':sport'    => $account->getFavoriteSport(),
                    ':location' => $account->getLocation(),
                    ':pic'      => $account->getProfilePicURL(),
                    ':birth'    => $account->getBirthDate()?->format('Y-m-d'),
                    ':lat'      => $account->getLatitude(),
                    ':lng'      => $account->getLongitude(),
                ]
            );

            return;
        }

        if ($account instanceof FacilityOwner) {
            $this->execute(
                'INSERT INTO `FacilityOwner` (`baseUserId`, `bankName`, `bankAccountNum`, `businessRegNum`)
                 VALUES (:id, :bank, :acct, :reg)',
                [
                    ':id'   => $account->getBaseUserId(),
                    ':bank' => $account->getBankName(),
                    ':acct' => $account->getBankAccountNum(),
                    ':reg'  => $account->getBusinessRegNum(),
                ]
            );

            return;
        }

        if ($account instanceof Admin) {
            $this->execute(
                'INSERT INTO `Admin` (`baseUserId`, `adminId`) VALUES (:id, :adminId)',
                [':id' => $account->getBaseUserId(), ':adminId' => $account->getAdminId()]
            );
        }
    }

    private function updateRoleRow(Account $account): void
    {
        if ($account instanceof User) {
            $this->execute(
                'UPDATE `User`
                    SET `favoriteSport` = :sport, `location` = :location, `profilePicURL` = :pic,
                        `birthDate` = :birth, `latitude` = :lat, `longitude` = :lng
                  WHERE `baseUserId` = :id',
                [
                    ':sport'    => $account->getFavoriteSport(),
                    ':location' => $account->getLocation(),
                    ':pic'      => $account->getProfilePicURL(),
                    ':birth'    => $account->getBirthDate()?->format('Y-m-d'),
                    ':lat'      => $account->getLatitude(),
                    ':lng'      => $account->getLongitude(),
                    ':id'       => $account->getBaseUserId(),
                ]
            );

            return;
        }

        if ($account instanceof FacilityOwner) {
            $this->execute(
                'UPDATE `FacilityOwner`
                    SET `bankName` = :bank, `bankAccountNum` = :acct, `businessRegNum` = :reg
                  WHERE `baseUserId` = :id',
                [
                    ':bank' => $account->getBankName(),
                    ':acct' => $account->getBankAccountNum(),
                    ':reg'  => $account->getBusinessRegNum(),
                    ':id'   => $account->getBaseUserId(),
                ]
            );

            return;
        }

        if ($account instanceof Admin) {
            $this->execute(
                'UPDATE `Admin` SET `adminId` = :adminId WHERE `baseUserId` = :id',
                [':adminId' => $account->getAdminId(), ':id' => $account->getBaseUserId()]
            );
        }
    }

    private function existsWith(string $column, string $value, ?string $exceptId): bool
    {
        $this->assertColumn($column);

        $sql    = sprintf('SELECT COUNT(*) AS total FROM `BaseUser` WHERE `%s` = :value', $column);
        $params = [':value' => $value];

        if ($exceptId !== null) {
            $sql .= ' AND `baseUserId` <> :except';
            $params[':except'] = $exceptId;
        }

        $row = $this->selectOne($sql, $params);

        return ((int) ($row['total'] ?? 0)) > 0;
    }

    private function toDate(mixed $value, string $format): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $parsed === false ? null : $parsed;
    }

    private function toNullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
