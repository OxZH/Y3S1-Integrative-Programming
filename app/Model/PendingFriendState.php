<?php
// Base interface for the friend state (state pattern). Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\FriendState;

class PendingFriendState implements IFriendState
{
    public function value(): string
    {
        return FriendState::PENDING->value;
    }

    public function accept(FriendConnection $fc): void
    {
        $fc->setState(new AcceptedFriendState());
    }

    public function reject(FriendConnection $fc): void
    {
        $fc->setState(new RejectedFriendState());
    }

    public function remove(FriendConnection $fc): void
    {
        $fc->setState(new RemovedFriendState());
    }
}
