<?php
// Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use DateTimeImmutable;

class BaseRating extends Entity
{
    public function __construct(
        private string $baseRatingId,
        private string $authorId, // store id for now
        private DateTimeImmutable $createdAt,
    ) {}

    public function getIdentity(): ?string
    {
        return $this->baseRatingId;
    }

    // getters here
    protected function getBaseRatingId(): string
    {
        return $this->baseRatingId;
    }

    protected function getAuthorId(): string
    {
        return $this->authorId;
    }

    protected function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
