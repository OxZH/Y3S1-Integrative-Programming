<?php
// Searchable directory of active profiles.

use App\Domain\ProfileImage;
use App\Model\FacilityOwner;
use App\Model\User;

/** @var string $query */
/** @var \App\Model\Account[] $users */
/** @var array<string,bool> $friendIds */
/** @var bool $includeOwners */
?>
<div class="page-head">
    <div>
        <h1>Discover users</h1>
        <p class="lede lede-flush">Find players to game with, and the owners behind the venues.</p>
    </div>
</div>

<form method="get" action="<?= e(url('profile', 'discover')) ?>" class="search-bar">
    <?php // url() puts c and a in the action, but a GET form throws the query
          // string away and submits only its own fields, so they are repeated
          // here as hidden inputs. They carry no id - an id has to be unique on
          // the page, and only the search box needs one for its label. ?>
    <input type="hidden" name="c" value="profile">
    <input type="hidden" name="a" value="discover">

    <label for="user-search">Search by username</label>
    <div class="search-row">
        <input type="search" id="user-search" name="q" value="<?= e($query) ?>"
            placeholder="Enter a username" autocomplete="off">
        <button class="btn" type="submit">Search</button>
    </div>

    <label class="inline-label">
        <input type="checkbox" name="owners" value="1" <?= $includeOwners ? 'checked' : '' ?>>
        Include facility owners
    </label>
</form>

<?php if ($users === []): ?>
    <div class="card empty">
        <p><?= $query === '' ? 'No users found.' : 'No users match that search.' ?></p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($users as $user): ?>
            <?php
            $isOwner  = $user instanceof FacilityOwner;
            // A profile picture belongs to a player; an owner has no such field,
            // so they fall through to the initial placeholder below.
            $picture  = $user instanceof User ? ProfileImage::cacheBustedSrc($user->getProfilePicURL()) : '';
            $isFriend = isset($friendIds[$user->getBaseUserId()]);
            ?>
            <article class="card profile-card">
                <?php if ($picture !== ''): ?>
                    <img class="profile-card-image" src="<?= e($picture) ?>"
                        alt="<?= e($user->getUsername()) ?> profile picture">
                <?php else: ?>
                    <div class="profile-card-placeholder" aria-hidden="true">
                        <?= e(strtoupper(mb_substr($user->getUsername(), 0, 1))) ?>
                    </div>
                <?php endif; ?>

                <div>
                    <strong><?= e($user->getUsername()) ?></strong>
                    <?php if ($isOwner): ?>
                        <span class="pill">Facility owner</span>
                    <?php elseif ($isFriend): ?>
                        <span class="pill live">Friends</span>
                    <?php endif; ?>
                </div>

                <a class="btn small" href="<?= e(url('profile', 'showOther', ['id' => $user->getBaseUserId()])) ?>">
                    View profile
                </a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
