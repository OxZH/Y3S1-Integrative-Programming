<?php
// Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;

class UserRating extends BaseRating
{
    public function __construct(
        private string $rateeId, // store id for now
        private int $attitudeRating,
        private int $attendanceRating
    ) {}

    public function getIdentity(): ?string
    {
        return $this->getBaseRatingId();
    }

    // getters here
    public function getBaseRatingId(): string
    {
        return $this->getBaseRatingId();
    }

    public function getAuthorId(): string
    {
        return $this->getAuthorId();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->getCreatedAt();
    }

    public function getRateeId(): string
    {
        return $this->rateeId;
    }

    public function getAttitudeRating(): int
    {
        return $this->attitudeRating;
    }

    public function getAttendanceRating(): int
    {
        return $this->attendanceRating;
    }
}
