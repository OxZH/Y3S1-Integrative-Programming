<?php
// Event persistence and queries. Author: Goh Jian Yu

namespace App\Model;

use App\Competitiveness;
use App\Core\DataMapper;
use App\Core\Entity;
use App\EventStatus;
use App\EventVisibility;
use App\FitnessRequirement;
use App\SkillLevel;
use DateTimeImmutable;
use InvalidArgumentException;

final class EventMapper extends DataMapper
{
    private $facilities = null;
    private $accounts = null;

    protected function table(): string
    {
        return 'Event';
    }

    protected function primaryKey(): string
    {
        return 'eventId';
    }

    protected function columns(): array
    {
        return [
            'eventId', 'hostId', 'facilityId', 'name', 'sport', 'eventDate',
            'startTime', 'endTime', 'minParticipants', 'maxParticipants', 'status',
            'skillLevel', 'fitnessRequirement', 'competitiveness', 'visibility',
            'feePerParticipant', 'createdAt',
        ];
    }

    protected function toEntity(array $row): Entity
    {
        $event = new Event(
            (string) $row['eventId'],
            (string) $row['name'],
            (string) $row['sport'],
            new DateTimeImmutable((string) $row['eventDate']),
            (string) $row['startTime'],
            (string) $row['endTime'],
            (int) $row['minParticipants'],
            (int) $row['maxParticipants'],
            SkillLevel::from((string) $row['skillLevel']),
            FitnessRequirement::from((string) $row['fitnessRequirement']),
            Competitiveness::from((string) $row['competitiveness']),
            EventVisibility::from((string) $row['visibility']),
            EventStatus::from((string) $row['status']),
            (float) $row['feePerParticipant'],
            new DateTimeImmutable((string) $row['createdAt'])
        );

        $hostId     = (string) $row['hostId'];
        $facilityId = (string) $row['facilityId'];

        $event->setLoader('host', function () use ($hostId) { return $this->accounts()->findAccount($hostId); });
        $event->setLoader('location', function () use ($facilityId) { return $this->facilities()->find($facilityId); });

        return $event;
    }

    protected function toRow(Entity $entity): array
    {
        if (!$entity instanceof Event) {
            throw new InvalidArgumentException('EventMapper can only persist an Event.');
        }

        $host     = $entity->getHost();
        $facility = $entity->getLocation();

        if ($host === null || $facility === null) {
            throw new InvalidArgumentException('An event needs a host and a facility.');
        }

        return [
            'eventId'            => $entity->getEventId(),
            'hostId'             => $host->getBaseUserId(),
            'facilityId'         => $facility->getFacilityId(),
            'name'               => $entity->getName(),
            'sport'              => $entity->getSport(),
            'eventDate'          => $entity->getEventDate()->format('Y-m-d'),
            'startTime'          => $entity->getStartTime(),
            'endTime'            => $entity->getEndTime(),
            'minParticipants'    => $entity->getMinParticipants(),
            'maxParticipants'    => $entity->getMaxParticipants(),
            'status'             => $entity->getStatus()->value,
            'skillLevel'         => $entity->getSkillLevel()->value,
            'fitnessRequirement' => $entity->getFitnessRequirement()->value,
            'competitiveness'    => $entity->getCompetitiveness()->value,
            'visibility'         => $entity->getVisibility()->value,
            'feePerParticipant'  => number_format($entity->getFeePerParticipant(), 2, '.', ''),
            'createdAt'          => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    // Events already using part of the requested slot. Two intervals overlap
    // when each starts before the other ends, so touching ends do not clash and
    // a court can change hands at 20:00. A cancelled event releases its slot.
    public function findClashes(
        string $facilityId,
        DateTimeImmutable $date,
        string $startTime,
        string $endTime,
        ?string $ignoreEventId = null
    ): array {
        $sql = 'SELECT * FROM `Event`
                 WHERE `facilityId` = :facilityId
                   AND `eventDate` = :eventDate
                   AND `status` <> :cancelled
                   AND `startTime` < :endTime
                   AND `endTime` > :startTime';

        $params = [
            ':facilityId' => $facilityId,
            ':eventDate'  => $date->format('Y-m-d'),
            ':cancelled'  => EventStatus::CANCELLED->value,
            ':startTime'  => $startTime,
            ':endTime'    => $endTime,
        ];

        if ($ignoreEventId !== null) {
            $sql .= ' AND `eventId` <> :ignore';
            $params[':ignore'] = $ignoreEventId;
        }

        $events = $this->hydrateAll($this->select($sql, $params));

        return $events;
    }

    public function findPublishedUpcoming(?string $sport = null, int $limit = 50): array
    {
        $sql    = 'SELECT * FROM `Event` WHERE `status` = :status AND `eventDate` >= CURDATE()';
        $params = [':status' => EventStatus::PUBLISHED->value];

        if ($sport !== null && $sport !== '') {
            $sql .= ' AND `sport` = :sport';
            $params[':sport'] = $sport;
        }

        $sql .= ' ORDER BY `eventDate`, `startTime` LIMIT ' . min(100, max(1, $limit));

        $events = $this->hydrateAll($this->select($sql, $params));

        return $events;
    }

    public function findByHost(string $hostId): array
    {
        $events = $this->hydrateAll($this->select(
            'SELECT * FROM `Event` WHERE `hostId` = :hostId ORDER BY `eventDate` DESC, `startTime` DESC',
            [':hostId' => $hostId]
        ));

        return $events;
    }

    public function findByFacility(string $facilityId, bool $upcomingOnly = true): array
    {
        $sql = 'SELECT * FROM `Event` WHERE `facilityId` = :facilityId AND `status` <> :cancelled';

        if ($upcomingOnly) {
            $sql .= ' AND `eventDate` >= CURDATE()';
        }

        $sql .= ' ORDER BY `eventDate`, `startTime`';

        $events = $this->hydrateAll($this->select($sql, [
            ':facilityId' => $facilityId,
            ':cancelled'  => EventStatus::CANCELLED->value,
        ]));

        return $events;
    }

    // EventRegistration belongs to the Discovery module. Reading a single count
    // here rather than calling their service is deliberate: capacity shows on
    // every event card, and an HTTP round trip per card is not worth it.
    public function countParticipants(string $eventId): int
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM `EventRegistration`
              WHERE `eventId` = :eventId AND `status` IN (:confirmed, :attended)',
            [':eventId' => $eventId, ':confirmed' => 'CONFIRMED', ':attended' => 'ATTENDED']
        );

        return (int) ($row['total'] ?? 0);
    }

    // Evidence that the event was actually used. Invite links are excluded on
    // purpose: one is minted with every event, so counting them would mean no
    // event was ever hard-deletable. They cascade away with the row.
    // Two separate reasons an event cannot simply be deleted, counted apart so
    // the message we show says which one it actually is. Lumping them together
    // used to produce "people had already joined" for an event nobody joined,
    // where the only row was the organiser paying for their own venue.
    public function countDependents(string $eventId): array
    {
        $row = $this->selectOne(
            'SELECT (SELECT COUNT(*) FROM `Booking` WHERE `eventId` = :a) AS bookings,
                    (SELECT COUNT(*) FROM `EventRegistration` WHERE `eventId` = :b) AS registrations',
            [':a' => $eventId, ':b' => $eventId]
        );

        return [
            'bookings'      => (int) ($row['bookings'] ?? 0),
            'registrations' => (int) ($row['registrations'] ?? 0),
        ];
    }

    // Every sport already used, for the suggestions under the sport box on the
    // event form. There are only ever a handful of these however many events
    // exist, because it is one row per sport and not one per event.
    public function listSports(): array
    {
        $rows = $this->select('SELECT DISTINCT `sport` AS v FROM `Event` ORDER BY `sport`');

        return array_map(function ($r) { return $r['v']; }, $rows);
    }

    private function facilities(): FacilityMapper
    {
        return $this->facilities ??= new FacilityMapper($this->pdo);
    }

    private function accounts(): AccountMapper
    {
        return $this->accounts ??= new AccountMapper($this->pdo);
    }
}
