<?php
// Searchable directory of active player profiles.

use App\Model\User;

/** @var string $query */
/** @var User[] $users */
/** @var array<string,bool> $friendIds */
?>
<div class="page-head">
    <div>
        <h1>Discover users</h1>
        <p class="lede lede-flush">Find players and view their profiles.</p>
    </div>
</div>

<form method="get" action="<?= e(url('profile', 'discover')) ?>" class="search-bar">
    <label for="user-search">Search by username</label>
    <div class="search-row">
        <input type="hidden" id="user-search" name="c" value="profile">
        <input type="hidden" id="user-search" name="a" value="discover">
        <input type="search" id="user-search" name="q" value="<?= e($query) ?>"
            placeholder="Enter a username" autocomplete="off">
        <button class="btn" type="submit">Search</button>
    </div>
</form>

<?php if ($users === []): ?>
    <div class="card empty">
        <p><?= $query === '' ? 'No users found.' : 'No users match that search.' ?></p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($users as $user): ?>
            <article class="card profile-card">
                <?php if ($user->getProfilePicURL() !== null && $user->getProfilePicURL() !== ''): ?>
                    <img class="profile-card-image" src="<?= e($user->getProfilePicURL()) ?>"
                        alt="<?= e($user->getUsername()) ?> profile picture">
                <?php else: ?>
                    <div class="profile-card-placeholder" aria-hidden="true">
                        <?= e(strtoupper(substr($user->getUsername(), 0, 1))) ?>
                    </div>
                <?php endif; ?>

                <div>
                    <strong><?= e($user->getUsername()) ?></strong>
                    <?php if (isset($friendIds[$user->getBaseUserId()])): ?>
                        <span class="pill <?= isset($friendIds[$user->getBaseUserId()]) ? 'live' : 'dead' ?>">
                            Friends
                        </span>
                    <?php endif; ?>
                </div>

                <a class="btn small" href="<?= e(url('profile', 'showOther', ['id' => $user->getBaseUserId()])) ?>">
                    View profile
                </a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>