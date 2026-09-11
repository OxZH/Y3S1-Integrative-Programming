<?php
// Shown after the booking module returns. Author: Goh Jian Yu
// Receives: $event, $blocker, $canDelete

$facility = $event->getLocation();
$ready    = $blocker === null;
?>
<h1>Confirm your event</h1>
<p class="lede">
    <?= $ready
        ? 'The venue is booked and paid for. Publish the event and other players can start joining.'
        : 'The event is saved but not yet ready to publish.' ?>
</p>

<?php if (!$ready): ?>
    <div class="banner"><?= e($blocker) ?></div>
<?php endif; ?>

<div class="card">
    <h2><?= e($event->getName()) ?></h2>
    <div class="stat">
        <div><span>Sport</span><strong><?= e($event->getSport()) ?></strong></div>
        <div><span>When</span><strong><?= e($event->getEventDate()->format('D, d M Y')) ?>, <?= e(hhmm($event->getStartTime())) ?>&ndash;<?= e(hhmm($event->getEndTime())) ?></strong></div>
        <div><span>Players</span><strong><?= e((string) $event->getMinParticipants()) ?>&ndash;<?= e((string) $event->getMaxParticipants()) ?></strong></div>
        <div><span>Visibility</span><strong><?= e($event->getVisibility()->label()) ?></strong></div>
    </div>

    <?php if ($facility !== null): ?>
        <p class="small muted flush">
            <?= e($facility->getName()) ?> &middot; <?= e($facility->getFullAddress()) ?>
        </p>
    <?php endif; ?>
</div>

<div class="card toolbar toolbar-flush">
    <?php if ($ready): ?>
        <form method="post" action="<?= e(url('event', 'publish')) ?>" class="inline-form">
            <?= $csrfField ?>
            <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
            <button class="btn" type="submit">Confirm and publish</button>
        </form>
    <?php endif; ?>

    <?php // Still a draft, so the organiser can still change their mind about the details. ?>
    <?php if ($event->isDraft()): ?>
        <a class="btn ghost" href="<?= e(url('event', 'edit', ['id' => $event->getEventId()])) ?>">Edit details</a>
    <?php endif; ?>

    <a class="btn ghost" href="<?= e(url('event', 'show', ['id' => $event->getEventId()])) ?>">View event</a>

    <form method="post" action="<?= e(url('event', 'delete')) ?>" class="inline-form"
          data-confirm="<?= $canDelete ? 'Discard this event?' : 'Cancel this event? The venue has been paid for, so the record is kept.' ?>">
        <?= $csrfField ?>
        <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
        <button class="btn ghost" type="submit"><?= $canDelete ? 'Discard' : 'Cancel event' ?></button>
    </form>
</div>
