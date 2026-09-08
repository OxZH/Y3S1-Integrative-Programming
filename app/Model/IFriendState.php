<?php
// Base interface for the friend state (state pattern). Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Model;

interface IFriendState
{
    public function value(): string;

    public function accept(FriendConnection $fc): void;
    public function reject(FriendConnection $fc): void;
    public function remove(FriendConnection $fc): void;
}
