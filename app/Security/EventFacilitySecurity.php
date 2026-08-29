<?php
// Access control for the Event & Facility Management module. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Security;

use App\AuthorizationException;
use App\Model\Event;
use App\Model\Facility;

/**
 * Every record-level access decision this module makes.
 *
 * App\Security\Auth answers "who is signed in", which every module needs and
 * which all of us share. This class answers "may this person touch THIS venue
 * or THIS event", which only makes sense for the two entities this module owns.
 * Each module keeps its own file like this one, so nobody has to pick their
 * checks out of a shared class function by function.
 *
 * THREAT: insecure direct object reference (broken object level authorisation)
 *
 * Every page here addresses a record by id:
 *
 *     index.php?c=facility&a=edit&id=<facilityId>
 *     index.php?c=event&a=publish&id=<eventId>
 *
 * The obvious implementation loads the id and renders it, which checks only
 * that somebody is signed in. A facility owner can then edit the id in the
 * address bar to a venue belonging to a different business and change its
 * price, address or opening hours. They are authenticated; they are simply not
 * authorised for that row, and nothing ever asked.
 *
 * DEFENCE, in two layers
 *
 *   1. Unguessable identifiers. Every primary key is a version 4 UUID from a
 *      CSPRNG (see uuid() in app/helpers.php), not an AUTO_INCREMENT integer.
 *      With id=1,2,3 an attacker can walk the whole table and also learn how
 *      many venues a business has. On its own this is only obscurity, which is
 *      why it is not the whole answer.
 *
 *   2. An explicit ownership check on every record access - the methods below.
 *      The caller does not ask "is someone signed in", it asks "does THIS user
 *      own THIS row", and the request dies with 403 if not.
 *
 * A failed check and a missing record are reported identically. If "not yours"
 * read differently from "does not exist", the difference itself would confirm
 * which ids are real.
 */
final class EventFacilitySecurity
{
    private function __construct()
    {
    }

    /** The venue must belong to the signed-in owner. */
    public static function assertOwnsFacility(Facility $facility): void
    {
        $owner = Auth::requireFacilityOwner();

        if ($facility->getOwner()?->getBaseUserId() !== $owner->getBaseUserId()) {
            self::deny('facility', $facility->getFacilityId());
        }
    }

    /** The event must be hosted by the signed-in user. */
    public static function assertHostsEvent(Event $event): void
    {
        $account = Auth::requireLogin();

        if (!$event->isHostedBy($account->getBaseUserId())) {
            self::deny('event', $event->getEventId());
        }
    }

    /**
     * Refuse, record it for us, and tell the caller nothing useful.
     *
     * A run of these in the log is what an enumeration attempt looks like, so
     * the id is written down - but the message handed back is the one a missing
     * record produces.
     */
    private static function deny(string $recordType, ?string $recordId): never
    {
        error_log(sprintf(
            'Ownership check failed: user=%s tried to reach %s=%s',
            Auth::id() ?? 'anonymous',
            $recordType,
            $recordId ?? 'null'
        ));

        throw new AuthorizationException('That record does not exist, or you do not have access to it.');
    }
}
