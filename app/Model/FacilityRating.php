<?php
// Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;

class FacilityRating extends BaseRating
{
    public function __construct(
        private string $facilityId, // store id for now
        private int $facilityRating
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

    public function getFacilityId(): string
    {
        return $this->facilityId;
    }

    public function getFacilityRating(): int
    {
        return $this->facilityRating;
    }
}
