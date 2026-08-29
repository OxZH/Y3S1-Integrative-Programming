<?php
// Whether a venue is free for a requested slot. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Domain;

use App\Model\EventMapper;
use App\Model\Facility;
use DateTimeImmutable;
use DomainException;

/**
 * Two questions have to agree: is the venue open then (the Facility knows, since
 * opening hours are its own property), and is anything already booked into that
 * slot (the Event table knows). Keeping both here means the web form, the REST
 * endpoint and any admin tool reach the same answer.
 */
final class AvailabilityChecker
{
    public function __construct(private readonly EventMapper $events)
    {
    }

    /** @return string|null the reason it cannot be used, or null when it is free */
    public function check(
        Facility $facility,
        DateTimeImmutable $date,
        string $startTime,
        string $endTime,
        ?string $ignoreEventId = null
    ): ?string {
        if (!$facility->isBookable()) {
            return sprintf('%s is not currently accepting bookings.', $facility->getName());
        }

        if ($endTime <= $startTime) {
            return 'The end time must be later than the start time.';
        }

        if (!$facility->isWithinOperatingHours($startTime, $endTime)) {
            return sprintf(
                '%s is only open between %s and %s.',
                $facility->getName(),
                hhmm($facility->getOperationalHrsStart()),
                hhmm($facility->getOperationalHrsEnd())
            );
        }

        $clashes = $this->events->findClashes(
            (string) $facility->getFacilityId(),
            $date,
            $startTime,
            $endTime,
            $ignoreEventId
        );

        if ($clashes !== []) {
            // Does not name the other event or its host: anyone can probe times
            // at a venue they do not own, and the answer should just be "taken".
            return sprintf(
                'That slot overlaps an existing booking from %s to %s.',
                hhmm($clashes[0]->getStartTime()),
                hhmm($clashes[0]->getEndTime())
            );
        }

        return null;
    }

    /** @throws DomainException */
    public function assertAvailable(
        Facility $facility,
        DateTimeImmutable $date,
        string $startTime,
        string $endTime,
        ?string $ignoreEventId = null
    ): void {
        $reason = $this->check($facility, $date, $startTime, $endTime, $ignoreEventId);

        if ($reason !== null) {
            throw new DomainException($reason);
        }
    }

    /**
     * Free slots on one day, so an organiser is offered times that will work
     * instead of guessing and being rejected.
     *
     * @return array<int,array{start:string,end:string}>
     */
    public function openSlots(Facility $facility, DateTimeImmutable $date, int $slotHours = 1): array
    {
        $busy = [];

        foreach ($this->events->findByFacility((string) $facility->getFacilityId(), false) as $event) {
            if ($event->getEventDate()->format('Y-m-d') === $date->format('Y-m-d')) {
                $busy[] = [$event->getStartTime(), $event->getEndTime()];
            }
        }

        $slots  = [];
        $cursor = (int) substr($facility->getOperationalHrsStart(), 0, 2);
        $close  = (int) substr($facility->getOperationalHrsEnd(), 0, 2);

        while ($cursor + $slotHours <= $close) {
            $start = sprintf('%02d:00:00', $cursor);
            $end   = sprintf('%02d:00:00', $cursor + $slotHours);
            $free  = true;

            foreach ($busy as [$busyStart, $busyEnd]) {
                if ($start < $busyEnd && $end > $busyStart) {
                    $free = false;
                    break;
                }
            }

            if ($free) {
                $slots[] = ['start' => $start, 'end' => $end];
            }

            $cursor += $slotHours;
        }

        return $slots;
    }
}
