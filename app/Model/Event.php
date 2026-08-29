<?php
// Event entity. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Model;

use App\Competitiveness;
use App\Core\Entity;
use App\EventStatus;
use App\EventVisibility;
use App\FitnessRequirement;
use App\SkillLevel;
use DateTimeImmutable;
use DomainException;

/**
 * One organised game at one venue. The class diagram names the venue
 * association "location", so that is the accessor; getFacility() is an alias.
 *
 * The diagram also draws booking: Booking. That belongs to the Venue Booking &
 * Payment module and is not a property here - this module asks that module for
 * a booking's status over a web service instead of reading its tables.
 */
class Event extends Entity
{
    protected ?Account $host = null;
    protected ?Facility $location = null;

    public function __construct(
        private ?string $eventId,
        private string $name,
        private string $sport,
        private DateTimeImmutable $eventDate,
        private string $startTime,
        private string $endTime,
        private int $minParticipants,
        private int $maxParticipants,
        private SkillLevel $skillLevel,
        private FitnessRequirement $fitnessRequirement,
        private Competitiveness $competitiveness,
        private EventVisibility $visibility = EventVisibility::PUBLIC,
        private EventStatus $status = EventStatus::DRAFT,
        private float $feePerParticipant = 0.0,
        private ?DateTimeImmutable $createdAt = null
    ) {
        $this->createdAt ??= new DateTimeImmutable();
    }

    public function getIdentity(): ?string
    {
        return $this->eventId;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSport(): string
    {
        return $this->sport;
    }

    public function getEventDate(): DateTimeImmutable
    {
        return $this->eventDate;
    }

    public function getStartTime(): string
    {
        return $this->startTime;
    }

    public function getEndTime(): string
    {
        return $this->endTime;
    }

    public function getMinParticipants(): int
    {
        return $this->minParticipants;
    }

    public function getMaxParticipants(): int
    {
        return $this->maxParticipants;
    }

    public function getSkillLevel(): SkillLevel
    {
        return $this->skillLevel;
    }

    public function getFitnessRequirement(): FitnessRequirement
    {
        return $this->fitnessRequirement;
    }

    public function getCompetitiveness(): Competitiveness
    {
        return $this->competitiveness;
    }

    public function getVisibility(): EventVisibility
    {
        return $this->visibility;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function getFeePerParticipant(): float
    {
        return $this->feePerParticipant;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getHost(): ?Account
    {
        return $this->resolve('host');
    }

    public function setHost(Account $host): void
    {
        $this->host = $host;
    }

    public function getLocation(): ?Facility
    {
        return $this->resolve('location');
    }

    public function getFacility(): ?Facility
    {
        return $this->getLocation();
    }

    public function setLocation(Facility $facility): void
    {
        $this->location = $facility;
    }

    public function getStartsAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->eventDate->format('Y-m-d') . ' ' . $this->startTime);
    }

    public function getEndsAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->eventDate->format('Y-m-d') . ' ' . $this->endTime);
    }

    public function getDurationHours(): float
    {
        return round(($this->getEndsAt()->getTimestamp() - $this->getStartsAt()->getTimestamp()) / 3600, 2);
    }

    public function isInThePast(): bool
    {
        return $this->getEndsAt() < new DateTimeImmutable();
    }

    public function isPublished(): bool
    {
        return $this->status->isPublished();
    }

    public function isFriendsOnly(): bool
    {
        return $this->visibility === EventVisibility::FRIENDS_ONLY;
    }

    public function isHostedBy(string $userId): bool
    {
        return $this->getHost()?->getBaseUserId() === $userId;
    }

    // Only a quote at the venue's current rate. What is actually charged is
    // snapshotted onto Booking.bookingAmount by the Venue Booking module, so a
    // later price change cannot alter what someone already paid.
    public function quoteVenueCost(): float
    {
        $facility = $this->getLocation();

        return $facility === null ? 0.0 : round($facility->getBookingFee() * $this->getDurationHours(), 2);
    }

    public function publish(): void
    {
        if ($this->status === EventStatus::CANCELLED) {
            throw new DomainException('A cancelled event cannot be published.');
        }

        $this->status = EventStatus::PUBLISHED;
    }

    public function cancel(): void
    {
        if ($this->status === EventStatus::COMPLETED) {
            throw new DomainException('A completed event cannot be cancelled.');
        }

        $this->status = EventStatus::CANCELLED;
    }

    /** @param array<string,mixed> $changes */
    public function updateDetails(array $changes): void
    {
        $this->name               = $changes['name'] ?? $this->name;
        $this->sport              = $changes['sport'] ?? $this->sport;
        $this->eventDate          = $changes['eventDate'] ?? $this->eventDate;
        $this->startTime          = $changes['startTime'] ?? $this->startTime;
        $this->endTime            = $changes['endTime'] ?? $this->endTime;
        $this->minParticipants    = $changes['minParticipants'] ?? $this->minParticipants;
        $this->maxParticipants    = $changes['maxParticipants'] ?? $this->maxParticipants;
        $this->skillLevel         = $changes['skillLevel'] ?? $this->skillLevel;
        $this->fitnessRequirement = $changes['fitnessRequirement'] ?? $this->fitnessRequirement;
        $this->competitiveness    = $changes['competitiveness'] ?? $this->competitiveness;
        $this->visibility         = $changes['visibility'] ?? $this->visibility;
        $this->feePerParticipant  = $changes['feePerParticipant'] ?? $this->feePerParticipant;
    }
}
