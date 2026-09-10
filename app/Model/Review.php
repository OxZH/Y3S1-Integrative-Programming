<?php
// Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use App\ModerationStatus;
use DateTimeImmutable;

class Review extends Entity
{
    public function __construct(
        private string $reviewId,
        private string $authorId, // store id for now
        private ?string $facilityId,
        private string $targetUserId,
        private string $title,
        private string $comment,
        private int $votes,
        private DateTimeImmutable $reviewTimestamp,
        private ModerationStatus $moderationStatus = ModerationStatus::HIDDEN,
    ) {}

    public function getIdentity(): ?string
    {
        return $this->reviewId;
    }

    // getters here
    public function getReviewId(): string
    {
        return $this->reviewId;
    }

    public function getAuthorId(): string
    {
        return $this->authorId;
    }

    public function getFacilityId(): ?string
    {
        return $this->facilityId;
    }

    public function getTargetUserId(): string
    {
        return $this->targetUserId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getVotes(): int
    {
        return $this->votes;
    }

    public function getReviewTimestamp(): DateTimeImmutable
    {
        return $this->reviewTimestamp;
    }

    public function getModerationStatus(): ModerationStatus
    {
        return $this->moderationStatus;
    }
}
