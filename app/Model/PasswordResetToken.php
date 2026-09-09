<?php
// A single-use password reset ticket. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use DateTimeImmutable;

/**
 * What the database holds is the SHA-256 of the token, never the token. The raw
 * value exists only in the link that goes to the registered address, so reading
 * this table gives an attacker nothing they can redeem.
 */
final class PasswordResetToken extends Entity
{
    public function __construct(
        private string $passwordResetId,
        private string $baseUserId,
        private string $tokenHash,
        private DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $requestedAt = null,
        private ?DateTimeImmutable $usedAt = null,
        private ?string $requestIp = null
    ) {
    }

    public function getIdentity(): ?string
    {
        return $this->passwordResetId;
    }

    public function getPasswordResetId(): string
    {
        return $this->passwordResetId;
    }

    public function getBaseUserId(): string
    {
        return $this->baseUserId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRequestedAt(): ?DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getRequestIp(): ?string
    {
        return $this->requestIp;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function hasExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    /** Both conditions, so a caller cannot check one and forget the other. */
    public function isRedeemable(?DateTimeImmutable $now = null): bool
    {
        return !$this->isUsed() && !$this->hasExpired($now);
    }
}
