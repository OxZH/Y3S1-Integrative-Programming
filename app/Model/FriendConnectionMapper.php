<?php
// Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\Security\Auth;
use App\Core\DataMapper;
use App\Core\Entity;
use App\FriendState;
use RuntimeException;
use InvalidArgumentException;

$currentAccount = Auth::user();

if ($currentAccount === null) {
    throw new RuntimeException('No signed-in account found for this session.');
}

final class FriendConnectionMapper extends DataMapper
{
    private const SELECT = 'SELECT fc.friendConnectionId, fc.requesterId,
    --                              req.username AS requesterUsername,
                                    fc.addresseeId,
    --                              addr.username AS addresseeUsername,
                                    fc.state, fc.createdAt, fc.updatedAt
                              FROM `FriendConnection` fc';
    //                           JOIN `BaseUser` req ON fc.requesterId = req.baseUserId
    //                           JOIN `BaseUser` addr ON fc.addresseeId = addr.baseUserId';

    private AccountMapper $accountMapper;

    public function __construct(?\PDO $pdo = null)
    {
        parent::__construct($pdo);
        $this->accountMapper = new AccountMapper($pdo);
    }

    protected function table(): string
    {
        return 'FriendConnection';
    }

    protected function primaryKey(): string
    {
        return 'friendConnectionId';
    }

    protected function columns(): array
    {
        return ['friendConnectionId', 'requesterId', 'addresseeId', 'state', 'createdAt', 'updatedAt'];
    }

    /** @return FriendConnection[] */
    public function findFriends(Account $currentAccount): array
    {
        /** @var FriendConnection[] $connections */
        $connections = $this->hydrateAll($this->select(
            self::SELECT . ' WHERE (fc.addresseeId = :addresseeId OR fc.requesterId = :requesterId) AND fc.state = :state ORDER BY fc.createdAt DESC',
            [
                ':requesterId' => $currentAccount->getIdentity(),
                ':addresseeId' => $currentAccount->getIdentity(),
                ':state'       => FriendState::ACCEPTED->value,
            ]
        ));

        return $connections;
    }

    public function areFriends(string $userId1, string $userId2): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `FriendConnection`
             WHERE `state` = :state
             AND ((`requesterId` = :requesterId1 AND `addresseeId` = :addresseeId1)
             OR (`requesterId` = :requesterId2 AND `addresseeId` = :addresseeId2))
             LIMIT 1'
        );
        $statement->execute([
            ':state'        => FriendState::ACCEPTED->value,
            ':requesterId1' => $userId1,
            ':addresseeId1' => $userId2,
            ':requesterId2' => $userId2,
            ':addresseeId2' => $userId1,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return FriendConnection[] */
    public function findIncoming(Account $currentAccount): array
    {
        /** @var FriendConnection[] $connections */
        $connections = $this->hydrateAll($this->select(
            self::SELECT . ' WHERE fc.addresseeId = :addresseeId AND fc.state = :state ORDER BY fc.createdAt DESC',
            [
                ':addresseeId' => $currentAccount->getIdentity(),
                ':state'       => FriendState::PENDING->value,
            ]
        ));

        return $connections;
    }

    /** @return FriendConnection[] */
    public function findOutgoing(Account $currentAccount): array
    {
        /** @var FriendConnection[] $connections */
        $connections = $this->hydrateAll($this->select(
            self::SELECT . ' WHERE fc.requesterId = :requesterId AND fc.state = :state ORDER BY fc.createdAt DESC',
            [
                ':requesterId' => $currentAccount->getIdentity(),
                ':state'       => FriendState::PENDING->value,
            ]
        ));

        return $connections;
    }

    public function findById(string $id): ?FriendConnection
    {
        $found = $this->find($id);

        return $found instanceof FriendConnection ? $found : null;
    }

    protected function toEntity(array $row): Entity
    {
        if (!isset($row['friendConnectionId'], $row['requesterId'], $row['addresseeId'], $row['state'], $row['createdAt'])) {
            throw new RuntimeException('Missing required fields for FriendConnection entity.');
        }
        if (!isset($row['updatedAt'])) {
            $row['updatedAt'] = null;
        }

        $state = match ($row['state']) {
            FriendState::PENDING->value => new PendingFriendState(),
            FriendState::ACCEPTED->value => new AcceptedFriendState(),
            FriendState::REJECTED->value => new RejectedFriendState(),
            FriendState::REMOVED->value => new RemovedFriendState(),
            default => throw new RuntimeException('Invalid friend connection state: ' . $row['state'])
        };

        return new FriendConnection(
            (string) $row['friendConnectionId'],
            $this->accountMapper->findAccount((string) $row['requesterId']),
            $this->accountMapper->findAccount((string) $row['addresseeId']),
            $state,
            new \DateTimeImmutable((string) $row['createdAt']),
            isset($row['updatedAt']) ? new \DateTimeImmutable((string) $row['updatedAt']) : null
        );
    }

    protected function toRow(Entity $entity): array
    {
        if (!$entity instanceof FriendConnection) {
            throw new InvalidArgumentException('FriendConnectionMapper can only persist a FriendConnection.');
        }

        return [
            'friendConnectionId' => $entity->getFriendConnectionId(),
            'requesterId'        => $entity->getRequester()->getIdentity(),
            'addresseeId'        => $entity->getAddressee()->getIdentity(),
            'state'              => $entity->getState()->value(),
            'createdAt'          => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt'          => $entity->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
