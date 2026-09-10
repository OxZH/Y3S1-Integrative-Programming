<?php
// Advanced event filtering + the participation pipeline's entry point. Author: Ng Jing Siang

use App\Domain\Discovery\EventFeedItem;

/** @var EventFeedItem[] $events */
/** @var \App\Domain\Discovery\FeedFilterCriteria $criteria */
/** @var array<string,mixed> $input */
?>
<h1>Find a game</h1>
<p class="lede">Filter by sport, open spots and how far away, then sort by date, distance, rating or who is going.</p>

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

<form method="get" action="index.php" class="card toolbar">
    <input type="hidden" name="c" value="discovery">
    <input type="hidden" name="a" value="index">

    <label class="inline-label" for="sport">Sport</label>
    <input class="medium-field" type="text" id="sport" name="sport" placeholder="Badminton, Futsal"
           value="<?= e($input['sport'] ?? '') ?>">

    <label class="inline-label" for="minSpacesLeft">Min. spots left</label>
    <input class="medium-field" type="number" id="minSpacesLeft" name="minSpacesLeft" min="1"
           value="<?= e($input['minSpacesLeft'] ?? '') ?>">

    <label class="inline-label" for="radius">Within</label>
    <select class="medium-field" id="radius" name="radius" <?= $hasPosition ? '' : 'disabled' ?>>
        <option value="">Any distance</option>
        <?php foreach ([5, 10, 20, 50, 100] as $km): ?>
            <option value="<?= $km ?>" <?= (string) ($input['radius'] ?? '') === (string) $km ? 'selected' : '' ?>>
                <?= $km ?> km
            </option>
        <?php endforeach; ?>
    </select>

    <label class="inline-label" for="sort">Sort by</label>
    <select class="medium-field" id="sort" name="sort">
        <option value="date" <?= $criteria->sortBy === 'date' ? 'selected' : '' ?>>Date</option>
        <option value="distance" <?= $criteria->sortBy === 'distance' ? 'selected' : '' ?>>Distance</option>
        <option value="rating" <?= $criteria->sortBy === 'rating' ? 'selected' : '' ?>>Rating</option>
        <option value="friends" <?= $criteria->sortBy === 'friends' ? 'selected' : '' ?>>Friends going</option>
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
                    <?php if ($event->distanceKm !== null): ?>
                        <span class="pill live"><?= e(number_format($event->distanceKm, 1)) ?> km</span>
                    <?php endif; ?>
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
                    <?php if ($event->friendsAttending > 0): ?>
                        <div><span>Friends going</span><strong><?= e((string) $event->friendsAttending) ?></strong></div>
                    <?php endif; ?>
                </div>

                <?php if ($event->venueName !== null): ?>
                    <p class="small muted gap-below">
                        <?= e($event->venueName) ?><?= $event->city !== null ? ' &middot; ' . e($event->city) : '' ?>
                    </p>
                <?php endif; ?>

                <div class="actions">
                    <a class="btn small" href="<?= e(url('event', 'show', ['id' => $event->eventId])) ?>">View</a>
                    <form method="post" action="<?= e(url('discovery', 'join')) ?>" class="inline-form">
                        <?= $csrfField ?>
                        <input type="hidden" name="eventId" value="<?= e($event->eventId) ?>">
                        <button class="btn ghost small" type="submit">Join</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
