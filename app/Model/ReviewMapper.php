<?php
// Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\Security\Auth;
use App\Core\DataMapper;
use App\Core\Entity;
use App\ModerationStatus;
use DateTimeImmutable;
use RuntimeException;
use InvalidArgumentException;

$currentAccount = Auth::user();

// if ($currentAccount === null) {
//     throw new RuntimeException('No signed-in account found for this session.');
// }

final class ReviewMapper extends DataMapper
{
    protected function table(): string
    {
        return 'Review';
    }

    protected function primaryKey(): string
    {
        return 'reviewId';
    }

    protected function columns(): array
    {
        return ['reviewId', 'authorId', 'facilityId', 'targetUserId', 'reviewTitle', 'reviewComment', 'reviewVotes', 'reviewTimestamp', 'moderationStatus'];
    }

    public function findById(string $id): ?Review
    {
        $found = $this->find($id);

        return $found instanceof Review ? $found : null;
    }

    /** @return Review[] */
    public function findByFacilityId(string $facilityId): array
    {
        /** @var Review[] $reviews */
        $reviews = $this->hydrateAll($this->select(
            'SELECT * FROM `Review` WHERE facilityId = :facilityId ORDER BY reviewTimestamp DESC',
            [':facilityId' => $facilityId]
        ));

        return $reviews;
    }

    /** @return Review[] */
    public function findByTargetUserId(string $targetUserId): array
    {
        /** @var Review[] $reviews */
        $reviews = $this->hydrateAll($this->select(
            'SELECT * FROM `Review` WHERE targetUserId = :targetUserId ORDER BY reviewTimestamp DESC',
            [':targetUserId' => $targetUserId]
        ));

        return $reviews;
    }

    /** @return Review[] */
    public function findVisibleByTargetUserId(string $targetUserId, int $page = 1, int $perPage = 5): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(20, $perPage));
        $offset = ($page - 1) * $perPage;

        /** @var Review[] $reviews */
        $reviews = $this->hydrateAll($this->select(
            'SELECT * FROM `Review`
             WHERE `targetUserId` = :targetUserId AND `moderationStatus` = :status
             ORDER BY `reviewTimestamp` DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [':targetUserId' => $targetUserId, ':status' => ModerationStatus::VISIBLE->value]
        ));

        return $reviews;
    }

    public function countVisibleByTargetUserId(string $targetUserId): int
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM `Review`
             WHERE `targetUserId` = :targetUserId AND `moderationStatus` = :status',
            [':targetUserId' => $targetUserId, ':status' => ModerationStatus::VISIBLE->value]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function countByAuthorSince(string $authorId, DateTimeImmutable $since): int
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM `Review`
             WHERE `authorId` = :authorId AND `reviewTimestamp` >= :since',
            [
                ':authorId' => $authorId,
                ':since' => $since->format('Y-m-d H:i:s'),
            ]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function createUserReview(string $authorId, string $targetUserId, string $title, string $comment): void
    {
        $this->insert(new Review(
            uuid(),
            $authorId,
            null,
            $targetUserId,
            $title,
            $comment,
            0,
            new \DateTimeImmutable(),
            ModerationStatus::VISIBLE
        ));
    }

    public function vote(string $reviewId, string $voterId, int $voteValue): void
    {
        if (!in_array($voteValue, [-1, 1], true)) {
            throw new InvalidArgumentException('Review vote must be -1 or 1.');
        }

        $this->pdo->beginTransaction();

        try {
            $existing = $this->selectOne(
                'SELECT `reviewVoteId`, `voteValue` FROM `ReviewVote`
                 WHERE `reviewId` = :reviewId AND `voterId` = :voterId LIMIT 1',
                [':reviewId' => $reviewId, ':voterId' => $voterId]
            );

            if ($existing === null) {
                $this->execute(
                    'INSERT INTO `ReviewVote`
                        (`reviewVoteId`, `reviewId`, `voterId`, `voteValue`, `votedAt`)
                     VALUES (:id, :reviewId, :voterId, :voteValue, NOW())',
                    [':id' => uuid(), ':reviewId' => $reviewId, ':voterId' => $voterId, ':voteValue' => $voteValue]
                );
                $delta = $voteValue;
            } else {
                $oldValue = (int) $existing['voteValue'];
                $this->execute(
                    'UPDATE `ReviewVote` SET `voteValue` = :voteValue, `votedAt` = NOW()
                     WHERE `reviewVoteId` = :id',
                    [':voteValue' => $voteValue, ':id' => $existing['reviewVoteId']]
                );
                $delta = $voteValue - $oldValue;
            }

            $this->execute(
                'UPDATE `Review` SET `reviewVotes` = `reviewVotes` + :delta WHERE `reviewId` = :reviewId',
                [':delta' => $delta, ':reviewId' => $reviewId]
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    protected function toEntity(array $row): Entity
    {
        if (!array_key_exists('facilityId', $row) || !isset($row['reviewId'], $row['authorId'], $row['targetUserId'], $row['reviewTitle'], $row['reviewComment'], $row['reviewVotes'], $row['reviewTimestamp'], $row['moderationStatus'])) {
            throw new RuntimeException('Missing required fields for Review entity.');
        }

        return new Review(
            (string) $row['reviewId'],
            (string) $row['authorId'],
            $row['facilityId'] !== null ? (string) $row['facilityId'] : null,
            (string) $row['targetUserId'],
            (string) $row['reviewTitle'],
            (string) $row['reviewComment'],
            (int) $row['reviewVotes'],
            new \DateTimeImmutable((string) $row['reviewTimestamp']),
            ModerationStatus::from((string) $row['moderationStatus'])
        );
    }

    protected function toRow(Entity $entity): array
    {
        if (!$entity instanceof Review) {
            throw new InvalidArgumentException('ReviewMapper can only persist a Review.');
        }

        return [
            'reviewId' => $entity->getReviewId(),
            'authorId' => $entity->getAuthorId(),
            'facilityId' => $entity->getFacilityId() !== null ? $entity->getFacilityId() : null,
            'targetUserId' => $entity->getTargetUserId(),
            'reviewTitle' => $entity->getTitle(),
            'reviewComment' => $entity->getComment(),
            'reviewVotes' => $entity->getVotes(),
            'reviewTimestamp' => $entity->getReviewTimestamp()->format('Y-m-d H:i:s'),
            'moderationStatus' => $entity->getModerationStatus()->value,
        ];
    }
}
