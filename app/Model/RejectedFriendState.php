<?php
// Base interface for the friend state (state pattern). Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\FriendState;

class RejectedFriendState implements IFriendState
{
    public function value(): string
    {
        return FriendState::REJECTED->value;
    }

    public function accept(FriendConnection $fc): void
    {
        return; // do nothing, already rejected
    }

    public function reject(FriendConnection $fc): void
    {
        return; // do nothing, already rejected
    }

    public function remove(FriendConnection $fc): void
    {
        return; // do nothing, already rejected
    }
}
