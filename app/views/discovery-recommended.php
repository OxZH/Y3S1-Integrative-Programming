<?php
// Algorithmic recommendations. Author: Ng Jing Siang

use App\Domain\Discovery\EventFeedItem;

/** @var EventFeedItem[] $events */
?>
<h1>Recommended for you</h1>
<p class="lede">Based on your favourite sport and how close each game is.</p>

<?php if ($events === []): ?>
    <div class="card empty">
        <p>Nothing to recommend yet - set a favourite sport and location on your profile, or check back later.</p>
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
                    <span class="pill live"><?= e(number_format($event->recommendationScore ?? 0, 0)) ?> pts</span>
                </div>

                <?php if ($event->recommendationReason !== null): ?>
                    <p class="small muted"><?= e($event->recommendationReason) ?></p>
                <?php endif; ?>

                <div class="stat">
                    <div>
                        <span>When</span>
                        <strong><?= e($event->eventDate) ?>, <?= e(hhmm($event->startTime)) ?></strong>
                    </div>
                    <div>
                        <span>Spots left</span>
                        <strong><?= e((string) ($event->spacesLeft ?? '?')) ?> / <?= e((string) ($event->maxParticipants ?? '?')) ?></strong>
                    </div>
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
