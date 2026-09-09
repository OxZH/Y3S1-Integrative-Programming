<?php
// Event detail and organiser controls. Author: Goh Jian Yu
// Receives: $event, $participants, $isHost, $blocker

$facility = $event->getLocation();
$host     = $event->getHost();
$status   = $event->getStatus();

$statusClass = '';

if ($status->isPublished() || $status->value === 'ONGOING') {
    $statusClass = 'live';
} else if ($status->value === 'DRAFT' || $status->value === 'PENDING_PAYMENT') {
    $statusClass = 'wait';
} else if ($status->isCancelled()) {
    $statusClass = 'dead';
}
?>
<div class="page-head">
    <div>
        <h1><?= e($event->getName()) ?></h1>
        <p class="lede lede-flush">
            Organised by <strong><?= e($host?->getUsername() ?? 'unknown') ?></strong>
            <?php if ($facility !== null): ?>at <?= e($facility->getName()) ?><?php endif; ?>
        </p>
    </div>
    <div class="badge-group">
        <span class="pill <?= e($statusClass) ?>"><?= e($status->label()) ?></span>
        <span class="pill <?= $event->isFriendsOnly() ? 'wait' : 'live' ?>"><?= e($event->getVisibility()->label()) ?></span>
    </div>
</div>

<div class="row row-spaced">
    <div class="card">
        <h2>The game</h2>
        <div class="stat">
            <div><span>Sport</span><strong><?= e($event->getSport()) ?></strong></div>
            <div><span>Date</span><strong><?= e($event->getEventDate()->format('D, d M Y')) ?></strong></div>
            <div><span>Time</span><strong><?= e(hhmm($event->getStartTime())) ?>&ndash;<?= e(hhmm($event->getEndTime())) ?></strong></div>
            <div><span>Duration</span><strong><?= e(number_format($event->getDurationHours(), 1)) ?> h</strong></div>
        </div>
        <div class="stat">
            <div><span>Skill level</span><strong><?= e($event->getSkillLevel()->label()) ?></strong></div>
            <div><span>Fitness</span><strong><?= e($event->getFitnessRequirement()->label()) ?></strong></div>
            <div><span>Nature</span><strong><?= e($event->getCompetitiveness()->label()) ?></strong></div>
        </div>
        <div class="stat">
            <div><span>Players</span><strong><?= e((string) $participants) ?> of <?= e((string) $event->getMaxParticipants()) ?></strong></div>
            <div><span>Minimum</span><strong><?= e((string) $event->getMinParticipants()) ?></strong></div>
            <div><span>Fee per player</span><strong><?= e(money($event->getFeePerParticipant())) ?></strong></div>
        </div>
    </div>

    <div class="card">
        <h2>The venue</h2>
        <?php if ($facility !== null): ?>
            <p class="flush"><strong><?= e($facility->getName()) ?></strong></p>
            <p class="small muted sub"><?= e($facility->getFullAddress()) ?></p>
            <div class="stat">
                <div><span>Hourly fee</span><strong><?= e(money($facility->getBookingFee())) ?></strong></div>
                <div><span>Venue cost for this slot</span><strong><?= e(money($event->quoteVenueCost())) ?></strong></div>
            </div>
            <p class="small muted">
                A quote at the venue's current rate. The amount charged is fixed when the booking is made.
            </p>
            <a class="btn ghost small" href="<?= e(url('facility', 'show', ['id' => $facility->getFacilityId()])) ?>">View venue</a>
        <?php else: ?>
            <p class="muted">Venue details are unavailable.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($isHost): ?>
    <h2>Organiser controls</h2>

    <?php if ($blocker !== null && !$status->isPublished() && !$status->isCancelled()): ?>
        <div class="banner"><?= e($blocker) ?></div>
    <?php endif; ?>

    <div class="card toolbar toolbar-flush">
        <?php if (!$status->isPublished() && !$status->isCancelled()): ?>
            <form method="post" action="<?= e(url('event', 'publish')) ?>" class="inline-form">
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                <button class="btn" type="submit">Publish this event</button>
            </form>
        <?php endif; ?>

        <a class="btn ghost" href="<?= e(url('event', 'invites', ['id' => $event->getEventId()])) ?>">Manage invite links</a>

        <?php if (!$status->isCancelled()): ?>
            <form method="post" action="<?= e(url('event', 'cancel')) ?>" class="inline-form"
                  data-confirm="Cancel this event?">
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                <button class="btn danger" type="submit">Cancel event</button>
            </form>
        <?php endif; ?>

        <form method="post" action="<?= e(url('event', 'delete')) ?>" class="inline-form"
              data-confirm="Delete this event?">
            <?= $csrfField ?>
            <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
            <button class="btn ghost" type="submit">Delete</button>
        </form>
    </div>

    <p class="small muted">
        Publishing asks the Venue Booking module whether this event's booking is confirmed and paid.
        Until it answers yes, the event stays hidden from other players.
    </p>
<?php endif; ?>
