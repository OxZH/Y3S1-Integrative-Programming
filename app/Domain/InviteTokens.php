<?php
// Mints invite link tokens. Author: Goh Jian Yu

namespace App\Domain;

use App\Model\Event;
use App\Model\EventInvite;
use DateTimeImmutable;

// The token is a bearer credential, so the whole weight of the friends-only
// setting rests on how it is generated. 32 bytes from random_bytes: uniqid is
// the clock, mt_rand's state can be recovered from a few outputs, and
// md5(time()) is guessable by anyone who knows roughly when the link was made.
//
// Default expiry is the end of the event - a link to a finished game has no
// reason to keep working.
final class InviteTokens
{
    // Built but not saved; storing it is the facade's job.
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

    // Pulls the token out of whatever the player pasted. Somebody handed a link
    // in a chat usually clicks it, but somebody who copied it and came back
    // later pastes it into the box on Find a game, and what lands there is the
    // whole address rather than the code. Both are accepted.
    //
    // Nothing here decides whether the token is real. This only recognises the
    // shape of one, which is the 64 hex characters bin2hex(random_bytes(32))
    // produces. Whether it exists, has expired or has been used up is the
    // database's answer, not a string's.
    public static function fromPastedLink(string $pasted): ?string
    {
        $pasted = trim($pasted);

        if (preg_match('/\btoken=([0-9a-f]{64})\b/i', $pasted, $found) === 1) {
            return strtolower($found[1]);
        }

        if (preg_match('/^[0-9a-f]{64}$/i', $pasted) === 1) {
            return strtolower($pasted);
        }

        return null;
    }
}
