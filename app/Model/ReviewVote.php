<?php
// Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use DateTimeImmutable;

class ReviewVote extends Entity
{
    public function __construct(
        private string $reviewVoteId,
        private string $voterId, // store id for now
        private int $voteValue,
        private DateTimeImmutable $votedAt,
    ) {}

    public function getIdentity(): ?string
    {
        return $this->reviewVoteId;
    }

    // getters here
    public function getReviewVoteId(): string
    {
        return $this->reviewVoteId;
    }

    public function getVoterId(): string
    {
        return $this->voterId;
    }

    public function getVoteValue(): int
    {
        return $this->voteValue;
    }

    public function getVotedAt(): DateTimeImmutable
    {
        return $this->votedAt;
    }
}
