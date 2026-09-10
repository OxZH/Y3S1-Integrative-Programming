<?php
// Access control for the Discovery & Event Matchmaking module. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Security;

use App\AuthorizationException;
use App\Model\EventRegistration;

/**
 * The one record-level decision this module owns: can this person cancel THIS
 * registration. Whether they may join in the first place is a different
 * question, answered by re-authorising against Event & Facility Management's
 * own visibility rule at the point of the write (see
 * App\Domain\Discovery\JoinEventService or wherever that call lives), not by an
 * ownership check here - see App\Security\EventFacilitySecurity for why the two
 * are kept apart as distinct mechanisms even though both are "access control".
 */
final class DiscoverySecurity
{
    private function __construct()
    {
    }

    public static function assertOwnsRegistration(EventRegistration $registration): void
    {
        $account = Auth::requireLogin();

        if ($registration->getUserId() !== $account->getBaseUserId()) {
            self::deny($registration->getEventRegistrationId());
        }
    }

    private static function deny(?string $recordId): never
    {
        error_log(sprintf(
            'Ownership check failed: user=%s tried to reach registration=%s',
            Auth::id() ?? 'anonymous',
            $recordId ?? 'null'
        ));

        throw new AuthorizationException('That record does not exist, or you do not have access to it.');
    }
}
