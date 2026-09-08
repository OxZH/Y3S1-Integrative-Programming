<?php
// Base interface for the friend state (state pattern). Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

use App\FriendState;

class AcceptedFriendState implements IFriendState
{
    public function value(): string
    {
        return FriendState::ACCEPTED->value;
    }

    public function accept(FriendConnection $fc): void
    {
        return; // do nothing, already accepted
    }

    public function reject(FriendConnection $fc): void
    {
        return; // do nothing, already accepted
    }

    public function remove(FriendConnection $fc): void
    {
        $fc->setState(new RemovedFriendState());
    }
}
