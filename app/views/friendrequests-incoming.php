<?php
// Incoming friend requests for the signed-in account.

use App\Model\FriendConnection;
use App\Security\Auth;

/** @var FriendConnection[] $requests */
$currentUser = Auth::requireLogin();
?>
<nav class="local-nav" aria-label="Friends navigation">
    <a class="local-nav-link" href="<?= e(url('friends', 'mine')) ?>">My friends</a>
    <a class="local-nav-link active" href="<?= e(url('friends', 'incoming')) ?>" aria-current="page">Incoming requests</a>
    <a class="local-nav-link" href="<?= e(url('friends', 'pending')) ?>">Pending requests</a>
</nav>

<div class="page-head">
    <div>
        <h1>Incoming friend requests</h1>
        <p class="lede lede-flush">Review requests from other accounts.</p>
    </div>
</div>

<?php if ($requests === []): ?>
    <div class="card empty">
        <p>You do not have any incoming friend requests yet.</p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($requests as $connection): ?>
            <article class="card">
                <div class="item-head">
                    <strong><?= e($connection->getRequester()->getUsername()) ?></strong>
                    <span class="pill live">Pending</span>
                </div>
                <form method="post" action="<?= e(url('friends', 'respond')) ?>" class="button-row">
                    <?= $csrfField ?? '' ?>
                    <input type="hidden" name="connectionId" value="<?= e($connection->getFriendConnectionId()) ?>">
                    <input type="hidden" name="returnTo" value="incoming">
                    <button class="btn small" type="submit" name="decision" value="accept">Accept</button>
                    <button class="btn ghost small" type="submit" name="decision" value="reject">Reject</button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>