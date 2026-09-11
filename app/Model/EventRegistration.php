<?php
// A user's registration for one event. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use App\RegistrationStatus;
use DateTimeImmutable;

/**
 * One row in EventRegistration, created when a user joins a game.
 * Related records are exposed as objects (getUser/getEvent/getInvite),
 * lazy loaded through Core\Entity::resolve().
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
     * Reuse a cancelled row when the user joins again. The (userId, eventId)
     * unique key would block a second row anyway.
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
