<?php
// Published upcoming games. Author: Goh Jian Yu

/** @var \App\Model\Event[] $events */
/** @var string|null $sport */
?>
<h1>Upcoming games</h1>
<p class="lede">
    Friends-only games appear here only if you are a friend of the organiser, or you opened one
    through an invite link.
</p>

<form method="get" action="index.php" class="card toolbar">
    <input type="hidden" name="c" value="event">
    <input type="hidden" name="a" value="index">
    <label class="inline-label" for="sport">Sport</label>
    <input class="medium-field" type="text" id="sport" name="sport" placeholder="Badminton, Futsal"
           value="<?= e($sport ?? '') ?>">
    <button class="btn small" type="submit">Filter</button>
    <?php if ($sport !== null && $sport !== ''): ?>
        <a class="btn ghost small" href="<?= e(url('event')) ?>">Clear</a>
    <?php endif; ?>
</form>

<?php if ($events === []): ?>
    <div class="card empty">
        <p>No games are published yet.</p>
        <p class="small">An event only appears here once its venue booking has been paid for.</p>
        <a class="btn" href="<?= e(url('event', 'create')) ?>">Create an event</a>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($events as $event): ?>
            <?php $facility = $event->getLocation(); ?>
            <div class="card">
                <div class="item-head">
                    <div>
                        <strong><?= e($event->getName()) ?></strong>
                        <div class="small muted">
                            <?= e($event->getSport()) ?>
                            &middot; <?= e($event->getCompetitiveness()->label()) ?>
                            &middot; <?= e($event->getSkillLevel()->label()) ?>
                        </div>
                    </div>
                    <span class="pill <?= $event->isFriendsOnly() ? 'wait' : 'live' ?>">
                        <?= e($event->getVisibility()->label()) ?>
                    </span>
                </div>

                <div class="stat">
                    <div>
                        <span>When</span>
                        <strong><?= e($event->getEventDate()->format('D d M')) ?>, <?= e(hhmm($event->getStartTime())) ?></strong>
                    </div>
                    <div><span>Players</span><strong>up to <?= e((string) $event->getMaxParticipants()) ?></strong></div>
                    <div><span>Fee</span><strong><?= e(money($event->getFeePerParticipant())) ?></strong></div>
                </div>

                <?php if ($facility !== null): ?>
                    <p class="small muted gap-below">
                        <?= e($facility->getName()) ?> &middot; <?= e($facility->getCity()) ?>
                    </p>
                <?php endif; ?>

                <a class="btn small" href="<?= e(url('event', 'show', ['id' => $event->getEventId()])) ?>">View game</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
