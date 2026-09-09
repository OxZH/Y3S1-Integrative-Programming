<?php
// One line of the authentication audit trail. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Model;

use App\AuthEventType;
use App\Core\Entity;
use DateTimeImmutable;

/**
 * A record that something happened to an account. Written on both success and
 * failure - a log that only records failures cannot show that an attacker who
 * guessed correctly on the sixth try then got in.
 *
 * Deliberately holds no password, no reset token and no session id. The log is
 * evidence, not a second copy of the credentials.
 */
final class AuthEvent extends Entity
{
    public function __construct(
        private string $authEventId,
        private ?string $baseUserId,
        private AuthEventType $eventType,
        private bool $succeeded,
        private ?string $emailTried = null,
        private ?string $ipAddress = null,
        private ?string $userAgent = null,
        private ?string $detail = null,
        private ?DateTimeImmutable $occurredAt = null,
        private ?string $username = null
    ) {
    }

    public function getIdentity(): ?string
    {
        return $this->authEventId;
    }

    public function getAuthEventId(): string
    {
        return $this->authEventId;
    }

    public function getBaseUserId(): ?string
    {
        return $this->baseUserId;
    }

    public function getEventType(): AuthEventType
    {
        return $this->eventType;
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function getEmailTried(): ?string
    {
        return $this->emailTried;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getOccurredAt(): ?DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Joined in for the audit screen, so it does not query per row. */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * The address someone typed at a failed login is shown masked. An admin
     * needs to recognise a pattern, not to read a list of real addresses.
     */
    public function getMaskedEmailTried(): ?string
    {
        if ($this->emailTried === null) {
            return null;
        }

        $at = strpos($this->emailTried, '@');

        if ($at === false || $at < 1) {
            return str_repeat('*', mb_strlen($this->emailTried));
        }

        $name = substr($this->emailTried, 0, $at);

        return mb_substr($name, 0, 2)
            . str_repeat('*', max(1, mb_strlen($name) - 2))
            . substr($this->emailTried, $at);
    }
}
