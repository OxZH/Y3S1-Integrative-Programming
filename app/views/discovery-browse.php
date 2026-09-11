<?php
// Find a game page. Author: Ng Jing Siang

use App\Domain\Discovery\EventFeedItem;

/** @var EventFeedItem[] $events */
/** @var \App\Domain\Discovery\FeedFilterCriteria $criteria */
/** @var array<string,mixed> $input */
/** @var string[] $sports */
?>
<h1>Find a game</h1>
<p class="lede">Filter by sport, open spots and how far away, then sort by date, distance or rating.</p>

<?php if (!$hasPosition): ?>
    <p class="small muted">
        <?php if (App\Security\Auth::check()): ?>
            Distance is measured from the address on your profile, and yours has not been placed on
            the map yet. Add or correct it on <a href="<?= e(url('profile', 'edit')) ?>">your profile</a>.
        <?php else: ?>
            <a href="<?= e(url('auth')) ?>">Sign in</a> to filter and sort games by how far they are from you.
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php // friends-only games are not listed here, so give a box to paste an invite link ?>
<?php if (App\Security\Auth::check()): ?>
    <form method="post" action="<?= e(url('event', 'redeem')) ?>" class="card toolbar">
        <?= $csrfField ?? '' ?>
        <label class="inline-label" for="inviteToken">Invited to a private game?</label>
        <input class="wide-field" type="text" id="inviteToken" name="token"
               placeholder="Paste the invite link you were sent">
        <button class="btn small" type="submit">Open invite</button>
    </form>
<?php endif; ?>

<form method="get" action="index.php" class="card toolbar">
    <input type="hidden" name="c" value="discovery">
    <input type="hidden" name="a" value="index">

    <label class="inline-label" for="sport">Sport</label>
    <select class="medium-field" id="sport" name="sport">
        <option value="">Any sport</option>
        <?php foreach ($sports as $sport): ?>
            <option value="<?= e($sport) ?>" <?= ($input['sport'] ?? '') === $sport ? 'selected' : '' ?>>
                <?= e($sport) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="inline-label" for="minSpacesLeft">Min. spots left</label>
    <input class="medium-field" type="number" id="minSpacesLeft" name="minSpacesLeft" min="1"
           value="<?= e($input['minSpacesLeft'] ?? '') ?>">

    <label class="inline-label" for="radius">Within (km)</label>
    <input class="medium-field" type="number" id="radius" name="radius" min="1" max="500" step="1"
           placeholder="Any distance" value="<?= e($input['radius'] ?? '') ?>"
           <?= $hasPosition ? '' : 'disabled' ?>>

    <label class="inline-label" for="sort">Sort by</label>
    <select class="medium-field" id="sort" name="sort">
        <option value="date" <?= $criteria->sortBy === 'date' ? 'selected' : '' ?>>Date</option>
        <option value="distance" <?= $criteria->sortBy === 'distance' ? 'selected' : '' ?>>Distance</option>
        <option value="rating" <?= $criteria->sortBy === 'rating' ? 'selected' : '' ?>>Rating</option>
    </select>

    <button class="btn small" type="submit">Filter</button>
    <a class="btn ghost small" href="<?= e(url('discovery')) ?>">Clear</a>
</form>

<?php if ($events === []): ?>
    <div class="card empty">
        <p>No games match those filters right now.</p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($events as $event): ?>
            <div class="card">
                <div class="item-head">
                    <div>
                        <strong><?= e($event->name) ?></strong>
                        <div class="small muted"><?= e($event->sport) ?></div>
                    </div>
                </div>

                <div class="stat">
                    <div>
                        <span>When</span>
                        <strong><?= e($event->eventDate) ?>, <?= e(hhmm($event->startTime)) ?></strong>
                    </div>
                    <div>
                        <span>Spots left</span>
                        <strong><?= e((string) ($event->spacesLeft ?? '?')) ?> / <?= e((string) ($event->maxParticipants ?? '?')) ?></strong>
                    </div>
                    <?php if ($event->rating !== null): ?>
                        <div><span>Rating</span><strong><?= e(number_format($event->rating, 1)) ?> &#9733;</strong></div>
                    <?php endif; ?>
                </div>

                <?php if ($event->venueName !== null): ?>
                    <p class="small muted gap-below">
                        <?= e($event->venueName) ?><?= $event->city !== null ? ' &middot; ' . e($event->city) : '' ?>
                    </p>
                <?php endif; ?>

                <div class="actions">
                    <a class="btn small" href="<?= e(url('event', 'show', ['id' => $event->eventId])) ?>">View</a>
                    <?php if (isset($joined[$event->eventId])): ?>
                        <span class="pill">Joined</span>
                    <?php endif; ?>
                </div>

                <?php
                // tags: public / friends only (same colours as the Event module) and distance
                $visibility = $event->visibility === null
                    ? null
                    : App\EventVisibility::tryFrom($event->visibility);
                ?>
                <?php if ($visibility !== null || $event->distanceKm !== null): ?>
                    <div class="chip-list spaced-top">
                        <?php if ($visibility !== null): ?>
                            <span class="pill <?= $visibility === App\EventVisibility::PUBLIC ? 'live' : 'wait' ?>">
                                <?= e($visibility->label()) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($event->distanceKm !== null): ?>
                            <span class="pill"><?= e(number_format($event->distanceKm, 1)) ?> km away</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
