<?php
// A user's registration for one event. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use App\RegistrationStatus;
use DateTimeImmutable;

/**
 * The row created when someone joins a game. Belongs entirely to the Discovery &
 * Event Matchmaking module - Event & Facility Management never reads or writes
 * this table, it only answers "is this event visible to you" over its own
 * web service.
 *
 * Relationships are exposed as object references (getUser/getEvent/getInvite),
 * not as raw ids, the same way Event and Facility do it in the neighbouring
 * module - see Core\Entity::resolve() for the lazy-loading mechanism.
 */
class EventRegistration extends Entity
{
    protected ?Account $user = null;
    protected ?Event $event = null;
    protected ?EventInvite $invite = null;

    public function __construct(
        private ?string $eventRegistrationId,
        private string $userId,
        private string $eventId,
        private RegistrationStatus $status = RegistrationStatus::CONFIRMED,
        private ?DateTimeImmutable $registerTime = null,
        private ?string $eventInviteId = null
    ) {
        $this->registerTime ??= new DateTimeImmutable();
    }

    public function getIdentity(): ?string
    {
        return $this->eventRegistrationId;
    }

    public function getEventRegistrationId(): ?string
    {
        return $this->eventRegistrationId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getStatus(): RegistrationStatus
    {
        return $this->status;
    }

    public function getRegisterTime(): DateTimeImmutable
    {
        return $this->registerTime;
    }

    public function getEventInviteId(): ?string
    {
        return $this->eventInviteId;
    }

    public function getUser(): ?Account
    {
        return $this->resolve('user');
    }

    public function setUser(Account $user): void
    {
        $this->user = $user;
    }

    // Note: this module's own copy of the event's shape (name, date, venue) - not
    // the authority on whether it may be viewed. Visibility stays with the
    // Event & Facility Management module's web service.
    public function getEvent(): ?Event
    {
        return $this->resolve('event');
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    public function getInvite(): ?EventInvite
    {
        return $this->resolve('invite');
    }

    public function setInvite(?EventInvite $invite): void
    {
        $this->invite = $invite;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function cancel(): void
    {
        $this->status = RegistrationStatus::CANCELLED;
    }

    /**
     * Re-activates a cancelled (or no-show) row for a second join, rather than
     * a second row existing for the same (userId, eventId) pair - the database
     * would refuse that anyway (uq_EventRegistration_user_event does not care
     * about status, only the pair).
     */
    public function rejoin(?string $eventInviteId = null): void
    {
        $this->status = RegistrationStatus::CONFIRMED;
        $this->registerTime = new DateTimeImmutable();

        if ($eventInviteId !== null) {
            $this->eventInviteId = $eventInviteId;
        }
    }

    public function markAttended(): void
    {
        $this->status = RegistrationStatus::ATTENDED;
    }

    public function markNoShow(): void
    {
        $this->status = RegistrationStatus::NO_SHOW;
    }
}
