<?php
// Mints invite link tokens. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Domain;

use App\Model\Event;
use App\Model\EventInvite;
use DateTimeImmutable;

/**
 * The token is a bearer credential, so the whole weight of the friends-only
 * setting rests on how it is generated. 32 bytes from random_bytes: uniqid is
 * the clock, mt_rand's state can be recovered from a few outputs, and
 * md5(time()) is guessable by anyone who knows roughly when the link was made.
 *
 * Default expiry is the end of the event - a link to a finished game has no
 * reason to keep working.
 */
final class InviteTokens
{
    /** Built but not saved; storing it is the facade's job. */
    public function generate(
        Event $event,
        string $createdBy,
        ?DateTimeImmutable $expiresAt = null,
        ?int $maxUses = null
    ): EventInvite {
        return new EventInvite(
            uuid(),
            (string) $event->getEventId(),
            bin2hex(random_bytes(32)),
            $createdBy,
            new DateTimeImmutable(),
            $expiresAt ?? $event->getEndsAt(),
            $maxUses !== null ? max(1, $maxUses) : null
        );
    }
}
