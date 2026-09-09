<?php
// Accepted friends for the signed-in account.

use App\Model\FriendConnection;
use App\Security\Auth;

/** @var FriendConnection[] $friends */
$currentUser = Auth::requireLogin();
?>
<nav class="local-nav" aria-label="Friends navigation">
    <a class="local-nav-link active" href="<?= e(url('friends', 'mine')) ?>" aria-current="page">My friends</a>
    <a class="local-nav-link" href="<?= e(url('friends', 'incoming')) ?>">Incoming requests</a>
    <a class="local-nav-link" href="<?= e(url('friends', 'pending')) ?>">Pending requests</a>
</nav>

<div class="page-head">
    <div>
        <h1>My friends</h1>
        <p class="lede lede-flush">People you are connected with.</p>
    </div>
</div>

<?php if ($friends === []): ?>
    <div class="card empty">
        <p>You do not have any accepted friends yet.</p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($friends as $connection): ?>
            <?php
            $friend = $connection->getRequester()->getBaseUserId() === $currentUser->getBaseUserId()
                ? $connection->getAddressee()
                : $connection->getRequester();
            ?>
            <article class="card">
                <div class="item-head">
                    <strong><?= e($friend->getUsername()) ?></strong>
                    <span class="pill live">Accepted</span>
                </div>
                <a class="btn small" href="<?= e(url('profile', 'showOther', ['id' => $friend->getBaseUserId()])) ?>">
                    View profile
                </a>
                <form method="post" action="<?= e(url('friends', 'respond')) ?>" class="button-row">
                    <?= $csrfField ?? '' ?>
                    <input type="hidden" name="connectionId" value="<?= e($connection->getFriendConnectionId()) ?>">
                    <input type="hidden" name="returnTo" value="mine">
                    <button class="btn ghost small" type="submit" name="decision" value="remove">Remove</button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>