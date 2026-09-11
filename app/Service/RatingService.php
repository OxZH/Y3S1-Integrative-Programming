<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AccountMapper;
use App\Model\FacilityMapper;
use App\Model\RatingMapper;
use App\ValidationException;

final class RatingService
{
    public function __construct(
        private RatingMapper $ratings = new RatingMapper(),
        private AccountMapper $accounts = new AccountMapper(),
        private FacilityMapper $facilities = new FacilityMapper()
    ) {}

    /** @return array{attitude:?int,attendance:?int}|null */
    public function userRating(string $authorId, string $rateeId): ?array
    {
        return $this->ratings->userRating($authorId, $rateeId);
    }

    public function facilityRating(string $authorId, string $facilityId): ?int
    {
        return $this->ratings->facilityRating($authorId, $facilityId);
    }

    public function rateFacility(string $authorId, string $targetId, int $rating): void
    {
        if (!in_array($rating, range(1, 5), true)) {
            throw new ValidationException(['rating' => 'A rating must be between one and five stars.']);
        }

        if ($this->facilities->find($targetId) === null) {
            throw new ValidationException(['target' => 'That facility could not be found.']);
        }

        $this->ratings->upsertFacilityRating($authorId, $targetId, $rating);
    }

    public function rateUser(string $authorId, string $targetId, int $attitudeRating, int $attendanceRating): void
    {
        foreach ([$attitudeRating, $attendanceRating] as $rating) {
            if (!in_array($rating, range(1, 5), true)) {
                throw new ValidationException(['rating' => 'Both ratings must be between one and five stars.']);
            }
        }

        if ($targetId === $authorId) {
            throw new ValidationException(['target' => 'You cannot rate your own profile.']);
        }
        if ($this->accounts->findAccount($targetId) === null) {
            throw new ValidationException(['target' => 'That profile could not be found.']);
        }

        $this->ratings->upsertUserRating($authorId, $targetId, $attitudeRating, $attendanceRating);
    }
}
