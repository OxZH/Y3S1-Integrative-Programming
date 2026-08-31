<?php
// Event persistence and queries. Author: Goh Jian Yu

declare(strict_types=1);

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
    private ?FacilityMapper $facilities = null;
    private ?AccountMapper $accounts = null;

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

        $event->setLoader('host', fn () => $this->accounts()->findAccount($hostId));
        $event->setLoader('location', fn () => $this->facilities()->find($facilityId));

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

    /**
     * Events already using part of the requested slot. Two intervals overlap
     * when each starts before the other ends, so touching ends do not clash and
     * a court can change hands at 20:00. A cancelled event releases its slot.
     *
     * @return Event[]
     */
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

        /** @var Event[] $events */
        $events = $this->hydrateAll($this->select($sql, $params));

        return $events;
    }

    /** @return Event[] */
    public function findPublishedUpcoming(?string $sport = null, int $limit = 50): array
    {
        $sql    = 'SELECT * FROM `Event` WHERE `status` = :status AND `eventDate` >= CURDATE()';
        $params = [':status' => EventStatus::PUBLISHED->value];

        if ($sport !== null && $sport !== '') {
            $sql .= ' AND `sport` = :sport';
            $params[':sport'] = $sport;
        }

        $sql .= ' ORDER BY `eventDate`, `startTime` LIMIT ' . min(100, max(1, $limit));

        /** @var Event[] $events */
        $events = $this->hydrateAll($this->select($sql, $params));

        return $events;
    }

    /** @return Event[] */
    public function findByHost(string $hostId): array
    {
        /** @var Event[] $events */
        $events = $this->hydrateAll($this->select(
            'SELECT * FROM `Event` WHERE `hostId` = :hostId ORDER BY `eventDate` DESC, `startTime` DESC',
            [':hostId' => $hostId]
        ));

        return $events;
    }

    /** @return Event[] */
    public function findByFacility(string $facilityId, bool $upcomingOnly = true): array
    {
        $sql = 'SELECT * FROM `Event` WHERE `facilityId` = :facilityId AND `status` <> :cancelled';

        if ($upcomingOnly) {
            $sql .= ' AND `eventDate` >= CURDATE()';
        }

        $sql .= ' ORDER BY `eventDate`, `startTime`';

        /** @var Event[] $events */
        $events = $this->hydrateAll($this->select($sql, [
            ':facilityId' => $facilityId,
            ':cancelled'  => EventStatus::CANCELLED->value,
        ]));

        return $events;
    }

    /**
     * EventRegistration belongs to the Discovery module. Reading a single count
     * here rather than calling their service is deliberate: capacity shows on
     * every event card, and an HTTP round trip per card is not worth it.
     */
    public function countParticipants(string $eventId): int
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM `EventRegistration`
              WHERE `eventId` = :eventId AND `status` IN (:confirmed, :attended)',
            [':eventId' => $eventId, ':confirmed' => 'CONFIRMED', ':attended' => 'ATTENDED']
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Evidence that the event was actually used. Invite links are excluded on
     * purpose: one is minted with every event, so counting them would mean no
     * event was ever hard-deletable. They cascade away with the row.
     */
    public function countDependents(string $eventId): int
    {
        $row = $this->selectOne(
            'SELECT (SELECT COUNT(*) FROM `Booking` WHERE `eventId` = :a)
                  + (SELECT COUNT(*) FROM `EventRegistration` WHERE `eventId` = :b) AS total',
            [':a' => $eventId, ':b' => $eventId]
        );

        return (int) ($row['total'] ?? 0);
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
