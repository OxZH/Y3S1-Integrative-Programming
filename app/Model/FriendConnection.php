<?php
// concrete model of the relationship between two Account objects. Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\Core\Entity;
use DateTimeImmutable;

class FriendConnection extends Entity
{
    public function __construct(
        private string $friendConnectionId,
        private Account $requester,
        private Account $addressee,
        private IFriendState $state,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt = null
    ) {}

    public function getIdentity(): ?string
    {
        return $this->friendConnectionId;
    }

    // getters here
    public function getFriendConnectionId(): string
    {
        return $this->friendConnectionId;
    }

    public function getRequester(): Account
    {
        return $this->requester;
    }

    public function getAddressee(): Account
    {
        return $this->addressee;
    }

    public function getState(): IFriendState
    {
        return $this->state;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setState(IFriendState $state): void
    {
        $this->state = $state;
    }
}
