<?php

declare(strict_types=1);

namespace App\Model;

use App\Core\DataMapper;
use App\Core\Entity;
use App\Core\Database;
use InvalidArgumentException;
use PDO;

final class RatingMapper extends DataMapper
{
    protected function table(): string
    {
        return 'BaseRating';
    }

    protected function primaryKey(): string
    {
        return 'ratingId';
    }

    protected function columns(): array
    {
        return ['ratingId', 'authorId', 'ratingType', 'createdAt'];
    }

    protected function toEntity(array $row): Entity
    {
        throw new InvalidArgumentException('RatingMapper does not hydrate BaseRating entities.');
    }

    protected function toRow(Entity $entity): array
    {
        throw new InvalidArgumentException('RatingMapper does not persist BaseRating entities.');
    }

    /** @return array{attitude:?int,attendance:?int}|null */
    public function userRating(string $authorId, string $rateeId): ?array
    {
        $row = $this->selectOne(
            'SELECT `ur`.`attitudeRating`, `ur`.`attendanceRating`
               FROM `BaseRating` `br`
               JOIN `UserRating` `ur` ON `ur`.`ratingId` = `br`.`ratingId`
              WHERE `br`.`authorId` = :authorId
                AND `br`.`ratingType` = \'USER\'
                AND `ur`.`rateeId` = :rateeId
              LIMIT 1',
            [':authorId' => $authorId, ':rateeId' => $rateeId]
        );

        return $row === null ? null : [
            'attitude' => (int) $row['attitudeRating'],
            'attendance' => (int) $row['attendanceRating'],
        ];
    }

    public function facilityRating(string $authorId, string $facilityId): ?int
    {
        $row = $this->selectOne(
            'SELECT `fr`.`facilityRating`
               FROM `BaseRating` `br`
               JOIN `FacilityRating` `fr` ON `fr`.`ratingId` = `br`.`ratingId`
              WHERE `br`.`authorId` = :authorId
                AND `br`.`ratingType` = \'FACILITY\'
                AND `fr`.`facilityId` = :facilityId
              LIMIT 1',
            [':authorId' => $authorId, ':facilityId' => $facilityId]
        );

        return $row === null ? null : (int) $row['facilityRating'];
    }

    public function upsertUserRating(string $authorId, string $rateeId, int $attitudeRating, int $attendanceRating): void
    {
        $pdo = Database::getConnection();
        Database::transaction(function () use ($pdo, $authorId, $rateeId, $attitudeRating, $attendanceRating): void {
            $row = $this->selectOne(
                'SELECT `br`.`ratingId`
                   FROM `BaseRating` `br`
                   JOIN `UserRating` `ur` ON `ur`.`ratingId` = `br`.`ratingId`
                  WHERE `br`.`authorId` = :authorId
                    AND `br`.`ratingType` = \'USER\'
                    AND `ur`.`rateeId` = :rateeId
                  LIMIT 1',
                [':authorId' => $authorId, ':rateeId' => $rateeId]
            );

            if ($row !== null) {
                $this->execute(
                    'UPDATE `UserRating`
                        SET `attitudeRating` = :attitudeRating,
                            `attendanceRating` = :attendanceRating
                      WHERE `ratingId` = :ratingId',
                    [
                        ':attitudeRating' => $attitudeRating,
                        ':attendanceRating' => $attendanceRating,
                        ':ratingId' => $row['ratingId'],
                    ]
                );
                return;
            }

            $ratingId = uuid();
            $pdo->prepare(
                'INSERT INTO `BaseRating` (`ratingId`, `authorId`, `ratingType`)
                 VALUES (:ratingId, :authorId, \'USER\')'
            )->execute([':ratingId' => $ratingId, ':authorId' => $authorId]);
            $pdo->prepare(
                'INSERT INTO `UserRating` (`ratingId`, `rateeId`, `attitudeRating`, `attendanceRating`)
                 VALUES (:ratingId, :rateeId, :attitudeRating, :attendanceRating)'
            )->execute([
                ':ratingId' => $ratingId,
                ':rateeId' => $rateeId,
                ':attitudeRating' => $attitudeRating,
                ':attendanceRating' => $attendanceRating,
            ]);
        });
    }

    public function upsertFacilityRating(string $authorId, string $facilityId, int $rating): void
    {
        $pdo = Database::getConnection();
        Database::transaction(function () use ($pdo, $authorId, $facilityId, $rating): void {
            $row = $this->selectOne(
                'SELECT `br`.`ratingId`
                   FROM `BaseRating` `br`
                   JOIN `FacilityRating` `fr` ON `fr`.`ratingId` = `br`.`ratingId`
                  WHERE `br`.`authorId` = :authorId
                    AND `br`.`ratingType` = \'FACILITY\'
                    AND `fr`.`facilityId` = :facilityId
                  LIMIT 1',
                [':authorId' => $authorId, ':facilityId' => $facilityId]
            );

            if ($row !== null) {
                $this->execute(
                    'UPDATE `FacilityRating` SET `facilityRating` = :rating WHERE `ratingId` = :ratingId',
                    [':rating' => $rating, ':ratingId' => $row['ratingId']]
                );
                return;
            }

            $ratingId = uuid();
            $pdo->prepare(
                'INSERT INTO `BaseRating` (`ratingId`, `authorId`, `ratingType`)
                 VALUES (:ratingId, :authorId, \'FACILITY\')'
            )->execute([':ratingId' => $ratingId, ':authorId' => $authorId]);
            $pdo->prepare(
                'INSERT INTO `FacilityRating` (`ratingId`, `facilityId`, `facilityRating`)
                 VALUES (:ratingId, :facilityId, :rating)'
            )->execute([':ratingId' => $ratingId, ':facilityId' => $facilityId, ':rating' => $rating]);
        });
    }
}
