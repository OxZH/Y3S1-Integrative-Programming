<?php
// Whether an event may go live. Author: Goh Jian Yu

namespace App\Domain;

use App\Model\Event;
use App\Service\BookingStatus;
use App\Service\RemoteServices;
use App\ServiceUnavailableException;
use DomainException;

// The last condition is the interesting one: whether the venue is paid for is
// not in our tables at all, so we ask the Venue Booking module. If that cannot
// be confirmed we do not publish - an event advertised at a court nobody
// reserved is worse than one that publishes ten minutes late.
final class PublicationPolicy
{
    public function __construct(private RemoteServices $services)
    {
    }

    public function assertPublishable(Event $event): BookingStatus
    {
        if ($event->getStatus()->isCancelled()) {
            throw new DomainException('This event was cancelled and cannot be published.');
        }

        if ($event->isInThePast()) {
            throw new DomainException('This event has already finished.');
        }

        if ($event->isPublished()) {
            throw new DomainException('This event is already published.');
        }

        // Not caught: the organiser should be told to try again, which is true,
        // rather than have an outage silently read as "not paid".
        $booking = $this->services->bookingStatus((string) $event->getEventId());

        if (!$booking->isSettled()) {
            throw new DomainException(
                'The venue booking for this event is not settled yet. ' . $booking->describe()
            );
        }

        return $booking;
    }

    // Non-throwing form, for showing a hint on the event page.
    public function explainBlockers(Event $event): ?string
    {
        try {
            $this->assertPublishable($event);

            return null;
        } catch (DomainException $e) {
            return $e->getMessage();
        } catch (ServiceUnavailableException $e) {
            return 'The booking service is not responding, so payment cannot be confirmed right now.';
        }
    }
}
