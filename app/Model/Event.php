<?php
// Event entity. Author: Goh Jian Yu

namespace App\Model;

use App\Competitiveness;
use App\Core\Entity;
use App\EventStatus;
use App\EventVisibility;
use App\FitnessRequirement;
use App\SkillLevel;
use DateTimeImmutable;
use DomainException;

// One organised game at one venue.
//
// The class diagram names the venue association "location", so that is what the
// accessor is called. getFacility() is just an alias that reads better.
//
// The diagram also draws booking: Booking. That belongs to the Venue Booking &
// Payment module, so it is not a property here. This module asks that module
// for a booking's status over a web service instead of reading their tables.
class Event extends Entity
{
    private $eventId;
    private $name;
    private $sport;
    private $eventDate;
    private $startTime;
    private $endTime;
    private $minParticipants;
    private $maxParticipants;
    private $skillLevel;
    private $fitnessRequirement;
    private $competitiveness;
    private $visibility;
    private $status;
    private $feePerParticipant;
    private $createdAt;

    // protected and not private, so the lazy loader in Entity can reach them
    protected $host = null;
    protected $location = null;

    public function __construct(
        $eventId,
        $name,
        $sport,
        DateTimeImmutable $eventDate,
        $startTime,
        $endTime,
        $minParticipants,
        $maxParticipants,
        SkillLevel $skillLevel,
        FitnessRequirement $fitnessRequirement,
        Competitiveness $competitiveness,
        EventVisibility $visibility = EventVisibility::PUBLIC,
        EventStatus $status = EventStatus::DRAFT,
        $feePerParticipant = 0.0,
        DateTimeImmutable $createdAt = null
    ) {
        $this->eventId = $eventId;
        $this->name = $name;
        $this->sport = $sport;
        $this->eventDate = $eventDate;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->minParticipants = $minParticipants;
        $this->maxParticipants = $maxParticipants;
        $this->skillLevel = $skillLevel;
        $this->fitnessRequirement = $fitnessRequirement;
        $this->competitiveness = $competitiveness;
        $this->visibility = $visibility;
        $this->status = $status;
        $this->feePerParticipant = $feePerParticipant;
        $this->createdAt = $createdAt === null ? new DateTimeImmutable() : $createdAt;
    }

    public function getIdentity(): ?string
    {
        return $this->eventId;
    }

    public function getEventId()
    {
        return $this->eventId;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getSport()
    {
        return $this->sport;
    }

    public function getEventDate()
    {
        return $this->eventDate;
    }

    public function getStartTime()
    {
        return $this->startTime;
    }

    public function getEndTime()
    {
        return $this->endTime;
    }

    public function getMinParticipants()
    {
        return $this->minParticipants;
    }

    public function getMaxParticipants()
    {
        return $this->maxParticipants;
    }

    public function getSkillLevel()
    {
        return $this->skillLevel;
    }

    public function getFitnessRequirement()
    {
        return $this->fitnessRequirement;
    }

    public function getCompetitiveness()
    {
        return $this->competitiveness;
    }

    public function getVisibility()
    {
        return $this->visibility;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getFeePerParticipant()
    {
        return $this->feePerParticipant;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    // the organiser
    public function getHost()
    {
        return $this->resolve('host');
    }

    public function setHost(Account $host)
    {
        $this->host = $host;
    }

    // the venue, named location on the class diagram
    public function getLocation()
    {
        return $this->resolve('location');
    }

    // reads better at the call site than getLocation()
    public function getFacility()
    {
        return $this->getLocation();
    }

    public function setLocation(Facility $facility)
    {
        $this->location = $facility;
    }

    public function getStartsAt()
    {
        return new DateTimeImmutable($this->eventDate->format('Y-m-d') . ' ' . $this->startTime);
    }

    public function getEndsAt()
    {
        return new DateTimeImmutable($this->eventDate->format('Y-m-d') . ' ' . $this->endTime);
    }

    public function getDurationHours()
    {
        $seconds = $this->getEndsAt()->getTimestamp() - $this->getStartsAt()->getTimestamp();

        return round($seconds / 3600, 2);
    }

    public function isInThePast()
    {
        return $this->getEndsAt() < new DateTimeImmutable();
    }

    public function isPublished()
    {
        return $this->status->isPublished();
    }

    // A draft is the one state where the details are still entirely the
    // organiser's own business: nobody has been shown the game, nobody has
    // joined it, and the venue has not been paid for. It is therefore the only
    // state in which editing changes nothing anybody else is relying on.
    public function isDraft()
    {
        return $this->status === EventStatus::DRAFT;
    }

    public function hasBeenLive()
    {
        return $this->status->hasBeenLive();
    }

    public function isFriendsOnly()
    {
        return $this->visibility === EventVisibility::FRIENDS_ONLY;
    }

    public function isHostedBy($userId)
    {
        $host = $this->getHost();

        return $host !== null && $host->getBaseUserId() === $userId;
    }

    // What the venue would cost for this slot, at today's rate. Only a quote:
    // the amount actually charged is fixed by the Venue Booking module when the
    // booking is made, so a later price change cannot alter what was paid.
    public function quoteVenueCost()
    {
        $facility = $this->getLocation();

        if ($facility === null) {
            return 0.0;
        }

        return round($facility->getBookingFee() * $this->getDurationHours(), 2);
    }

    public function publish()
    {
        if ($this->status === EventStatus::CANCELLED) {
            throw new DomainException('A cancelled event cannot be published.');
        }

        $this->status = EventStatus::PUBLISHED;
    }

    public function cancel()
    {
        if ($this->status === EventStatus::COMPLETED) {
            throw new DomainException('A completed event cannot be cancelled.');
        }

        $this->status = EventStatus::CANCELLED;
    }

    public function complete()
    {
        if (!$this->isInThePast()) {
            throw new DomainException('An event can only be completed after its end time.');
        }

        if (!in_array($this->status, [EventStatus::PUBLISHED, EventStatus::FULL, EventStatus::ONGOING], true)) {
            throw new DomainException('Only a live event can be completed.');
        }

        $this->status = EventStatus::COMPLETED;
    }

    // $changes comes from the Validator, so only fields that passed are in it
    public function updateDetails(array $changes)
    {
        $this->name = isset($changes['name']) ? $changes['name'] : $this->name;
        $this->sport = isset($changes['sport']) ? $changes['sport'] : $this->sport;
        $this->eventDate = isset($changes['eventDate']) ? $changes['eventDate'] : $this->eventDate;
        $this->startTime = isset($changes['startTime']) ? $changes['startTime'] : $this->startTime;
        $this->endTime = isset($changes['endTime']) ? $changes['endTime'] : $this->endTime;
        $this->minParticipants = isset($changes['minParticipants']) ? $changes['minParticipants'] : $this->minParticipants;
        $this->maxParticipants = isset($changes['maxParticipants']) ? $changes['maxParticipants'] : $this->maxParticipants;
        $this->skillLevel = isset($changes['skillLevel']) ? $changes['skillLevel'] : $this->skillLevel;
        $this->fitnessRequirement = isset($changes['fitnessRequirement']) ? $changes['fitnessRequirement'] : $this->fitnessRequirement;
        $this->competitiveness = isset($changes['competitiveness']) ? $changes['competitiveness'] : $this->competitiveness;
        $this->visibility = isset($changes['visibility']) ? $changes['visibility'] : $this->visibility;
        $this->feePerParticipant = isset($changes['feePerParticipant']) ? $changes['feePerParticipant'] : $this->feePerParticipant;
    }
}
