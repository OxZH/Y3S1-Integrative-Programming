<?php
// Accepted friends for the signed-in account.

use App\Model\FriendConnection;
use App\Security\Auth;

/** @var FriendConnection[] $requests */
$currentUser = Auth::requireLogin();
?>
<nav class="local-nav" aria-label="Friends navigation">
    <a class="local-nav-link" href="<?= e(url('friends', 'mine')) ?>">My friends</a>
    <a class="local-nav-link" href="<?= e(url('friends', 'incoming')) ?>">Incoming requests</a>
    <a class="local-nav-link active" href="<?= e(url('friends', 'pending')) ?>" aria-current="page">Pending requests</a>
</nav>

<div class="page-head">
    <div>
        <h1>My friends</h1>
        <p class="lede lede-flush">People you are connected with.</p>
    </div>
</div>

<?php if ($requests === []): ?>
    <div class="card empty">
        <p>You have not sent any friend requests at the moment.</p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($requests as $connection): ?>
            <article class="card">
                <div class="item-head">
                    <strong><?= e($connection->getAddressee()->getUsername()) ?></strong>
                    <span class="pill live">Pending</span>
                </div>
                <form method="post" action="<?= e(url('friends', 'respond')) ?>" class="button-row">
                    <?= $csrfField ?? '' ?>
                    <input type="hidden" name="connectionId" value="<?= e($connection->getFriendConnectionId()) ?>">
                    <input type="hidden" name="returnTo" value="pending">
                    <button class="btn ghost small" type="submit" name="decision" value="remove">Remove</button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>