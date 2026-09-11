<?php
// Friend list and connection management. Author: Ooi Kean Wei

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Model\FriendConnectionMapper;
use App\Security\Auth;
use App\AuthorizationException;
use InvalidArgumentException;

final class FriendsController extends Controller
{
    public function mine(): void
    {
        $currentAccount = Auth::user();

        $this->view('friends-mine', [
            'title'  => 'My friends',
            'friends' => (new FriendConnectionMapper())->findFriends($currentAccount),
        ]);
    }

    public function incoming(): void
    {
        $currentAccount = Auth::user();

        $this->view('friendrequests-incoming', [
            'title'  => 'Incoming friend requests',
            'requests' => (new FriendConnectionMapper())->findIncoming($currentAccount),
        ]);
    }

    public function pending(): void
    {
        $currentAccount = Auth::user();

        $this->view('friendrequests-pending', [
            'title'  => 'Pending friend requests',
            'requests' => (new FriendConnectionMapper())->findOutgoing($currentAccount),
        ]);
    }

    public function respond(): void
    {
        $this->requirePostWithCsrf();

        $currentAccount = Auth::requireLogin();
        $returnTo = $_POST['returnTo'] ?? "mine";
        $decision = $_POST['decision'] ?? null;
        $connectionId = $_POST['connectionId'] ?? null;

        $connectionMapper = new FriendConnectionMapper();
        $connection = $connectionMapper->findById($connectionId);

        if (!in_array($returnTo, ['incoming', 'pending', 'mine'], true)) {
            $returnTo = 'mine';
        }

        if ($connectionId === null) {
            throw new \InvalidArgumentException('Missing connection ID.');
        }
        if (
            $connection->getAddressee()->getBaseUserId() !== $currentAccount->getBaseUserId()
            && $connection->getRequester()->getBaseUserId() !== $currentAccount->getBaseUserId()
        ) {
            throw new AuthorizationException('You cannot change this request.');
        }

        match ($decision) {
            'accept' => $connection->getState()->accept($connection),
            'reject' => $connection->getState()->reject($connection),
            'remove' => $connection->getState()->remove($connection),
            default => throw new InvalidArgumentException('Invalid decision value.'),
        };

        $connectionMapper->update($connection);
        $this->redirect(url('friends', $returnTo));
    }
}
