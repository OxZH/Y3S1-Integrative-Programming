<?php
// Who may see an event. Author: Goh Jian Yu

namespace App\Domain;

use App\Model\Event;
use App\Model\EventInvite;
use App\Service\RemoteServices;
use App\ServiceUnavailableException;

// Every Public vs Friends-Only rule lives here, so the web page, the REST
// endpoint and the invite route cannot disagree.
//
//   1 the host always sees their own event, in any state
//   2 an event that never went live is nobody else's business
//   3 a public event that went live is visible to everyone
//   4 a friends-only event that went live needs an accepted friendship, or a
//     usable invite link for that event
//
// Rule 2 asks whether the event was ever live, not whether it is live now. It
// used to ask isPublished(), which is true of PUBLISHED alone, and that quietly
// hid a game from the very people who played in it the moment its host marked
// it complete. The Discovery module's participation history asks this module to
// describe each past game, so those rows came back as "unavailable" with no
// name - the one thing a history of past games exists to show. A cancelled
// event was hidden the same way, which defeated cancelling rather than deleting
// so that players "can see that it is off".
//
// Widening this does not put finished games into the browse listings. What
// appears there is decided by EventMapper::findPublishedUpcoming(), which asks
// the database for published future events only. This class answers a different
// question, which is whether a given person may see a given event at all.
//
// Rule 4 needs the Social Networking module. If it cannot answer this returns
// false: hiding a private event during an outage is a mild inconvenience,
// showing one because the check could not run is the failure the setting exists
// to prevent.
final class VisibilityPolicy
{
    public function __construct(private RemoteServices $services)
    {
    }

    public function isVisibleTo(Event $event, ?string $viewerId, ?EventInvite $invite = null): bool
    {
        $hostId = $event->getHost()?->getBaseUserId();

        if ($viewerId !== null && $hostId !== null && $viewerId === $hostId) {
            return true;
        }

        if (!$event->hasBeenLive()) {
            return false;
        }

        if (!$event->isFriendsOnly()) {
            return true;
        }

        if ($invite !== null && $invite->isUsable() && $invite->getEventId() === $event->getEventId()) {
            return true;
        }

        if ($viewerId === null || $hostId === null) {
            return false;
        }

        try {
            return $this->services->areFriends($hostId, $viewerId);
        } catch (ServiceUnavailableException $e) {
            error_log('Friend check unavailable, denying access: ' . $e->getMessage());

            return false;
        }
    }

    // Public events short-circuit before any service call, so a listing of
    // public events costs zero HTTP requests.
    public function filterVisible(array $events, ?string $viewerId): array
    {
        return array_values(array_filter(
            $events,
            function ($event) use ($viewerId) { return $this->isVisibleTo($event, $viewerId); }
        ));
    }
}
