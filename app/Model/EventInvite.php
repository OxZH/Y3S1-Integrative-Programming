<?php
// Shareable invite link for an event. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use DateTimeImmutable;

/**
 * The token is a bearer credential - whoever holds the link can open a
 * friends-only event without being a friend - so it can expire, be capped to a
 * number of uses, and be revoked.
 */
class EventInvite extends Entity
{
    public function __construct(
        private ?string $eventInviteId,
        private string $eventId,
        private string $token,
        private string $createdBy,
        private ?DateTimeImmutable $createdAt = null,
        private ?DateTimeImmutable $expiresAt = null,
        private ?int $maxUses = null,
        private int $useCount = 0,
        private bool $revoked = false
    ) {
        $this->createdAt ??= new DateTimeImmutable();
    }

    public function getIdentity(): ?string
    {
        return $this->eventInviteId;
    }

    public function getEventInviteId(): ?string
    {
        return $this->eventInviteId;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function hasExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new DateTimeImmutable();
    }

    public function isExhausted(): bool
    {
        return $this->maxUses !== null && $this->useCount >= $this->maxUses;
    }

    // Every reason a link can be dead, in one place.
    public function isUsable(): bool
    {
        return !$this->revoked && !$this->hasExpired() && !$this->isExhausted();
    }

    public function recordUse(): void
    {
        $this->useCount++;
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function getShareableUrl(): string
    {
        return rtrim((string) config('app.base_url'), '/')
             . '/index.php?c=event&a=invite&token=' . $this->token;
    }
}
