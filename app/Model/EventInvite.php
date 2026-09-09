<?php
// Shareable invite link for an event. Author: Goh Jian Yu

namespace App\Model;

use App\Core\Entity;
use DateTimeImmutable;

// A link an organiser can send to somebody so they can open a friends-only
// event without being a friend. Because holding the link is enough to get in,
// it can be given an expiry date, capped to a number of uses, and revoked.
class EventInvite extends Entity
{
    private $eventInviteId;
    private $eventId;
    private $token;
    private $createdBy;
    private $createdAt;
    private $expiresAt;
    private $maxUses;
    private $useCount;
    private $revoked;

    public function __construct(
        $eventInviteId,
        $eventId,
        $token,
        $createdBy,
        DateTimeImmutable $createdAt = null,
        DateTimeImmutable $expiresAt = null,
        $maxUses = null,
        $useCount = 0,
        $revoked = false
    ) {
        $this->eventInviteId = $eventInviteId;
        $this->eventId = $eventId;
        $this->token = $token;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt === null ? new DateTimeImmutable() : $createdAt;
        $this->expiresAt = $expiresAt;
        $this->maxUses = $maxUses;
        $this->useCount = $useCount;
        $this->revoked = $revoked;
    }

    public function getIdentity(): ?string
    {
        return $this->eventInviteId;
    }

    public function getEventInviteId()
    {
        return $this->eventInviteId;
    }

    public function getEventId()
    {
        return $this->eventId;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function getExpiresAt()
    {
        return $this->expiresAt;
    }

    public function getMaxUses()
    {
        return $this->maxUses;
    }

    public function getUseCount()
    {
        return $this->useCount;
    }

    public function isRevoked()
    {
        return $this->revoked;
    }

    public function hasExpired()
    {
        return $this->expiresAt !== null && $this->expiresAt < new DateTimeImmutable();
    }

    public function isExhausted()
    {
        return $this->maxUses !== null && $this->useCount >= $this->maxUses;
    }

    // every reason a link can be dead, kept in one place
    public function isUsable()
    {
        return !$this->revoked && !$this->hasExpired() && !$this->isExhausted();
    }

    public function recordUse()
    {
        $this->useCount++;
    }

    public function revoke()
    {
        $this->revoked = true;
    }

    public function getShareableUrl()
    {
        return rtrim(config('app.base_url'), '/')
             . '/index.php?c=event&a=invite&token=' . $this->token;
    }
}
