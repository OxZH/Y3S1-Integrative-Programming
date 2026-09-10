<?php
// Venue detail. Author: Goh Jian Yu

use App\Security\Auth;
// Receives: $facility, $rating, $events, $slots, $slotDate

$owner = $facility->getOwner();

// Opening hours. A venue can close after midnight, so say which day it closes
// on rather than leaving "22:00-02:00" looking like a mistake.
$hours = hhmm($facility->getOperationalHrsStart()) . '–' . hhmm($facility->getOperationalHrsEnd());

if ($facility->closesAfterMidnight()) {
    $hours .= ' (next day)';
}
?>
<h1><?= e($facility->getName()) ?></h1>
<p class="lede"><?= e($facility->getFullAddress()) ?></p>

<?php if ($facility->getImageUrl() !== null): ?>
    <img class="venue-photo" src="<?= e(imageSrc($facility->getImageUrl())) ?>"
         alt="<?= e($facility->getName()) ?>">
<?php endif; ?>

<div class="row">
    <div class="card">
        <h2>Venue details</h2>
        <div class="stat">
            <div><span>Type</span><strong><?= e($facility->getType()) ?></strong></div>
            <div><span>Hourly fee</span><strong><?= e(money($facility->getBookingFee())) ?></strong></div>
            <div><span>Opening hours</span><strong><?= e($hours) ?></strong></div>
            <div><span>Status</span><strong><?= e($facility->getStatus()->label()) ?></strong></div>
        </div>
    </div>

    <div>
        <div class="card rating-block">
            <h2>Rating</h2>
            <?= stars($rating) ?>
            <p class="small muted sub-tight">From the Social Networking &amp; Review module.</p>
        </div>

        <div class="card">
            <h2>Operated by</h2>
            <?php if ($owner !== null): ?>
                <p class="flush"><strong><?= e($owner->getUsername()) ?></strong></p>
                <p class="small muted sub-tight">
                    <?= e($owner->getEmail()) ?><br>
                    <?= e($owner->getContactNumber()) ?>
                </p>
            <?php else: ?>
                <p class="muted">Owner details are unavailable.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<h2>Free slots on <?= e($slotDate->format('D, d M Y')) ?></h2>
<div class="card">
    <form method="get" action="index.php" class="toolbar">
        <input type="hidden" name="c" value="facility">
        <input type="hidden" name="a" value="show">
        <input type="hidden" name="id" value="<?= e((string) $facility->getFacilityId()) ?>">
        <label class="inline-label" for="date">Show a different day</label>
        <input class="narrow-field" type="date" id="date" name="date" value="<?= e($slotDate->format('Y-m-d')) ?>">
        <button class="btn ghost small" type="submit">Go</button>
    </form>

    <?php if ($slots === []): ?>
        <p class="muted small flush">No two-hour slot is free on this day.</p>
    <?php else: ?>
        <div class="chip-list">
            <?php foreach ($slots as $slot): ?>
                <span class="pill"><?= e(hhmm($slot['start'])) ?>&ndash;<?= e(hhmm($slot['end'])) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<h2>Games at this venue</h2>
<?php if ($events === []): ?>
    <div class="card empty"><p>No upcoming games here yet.</p></div>
<?php else: ?>
    <div class="card card-table">
        <table>
            <thead>
            <tr><th>Event</th><th>Sport</th><th>When</th><th>Players</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><strong><?= e($event->getName()) ?></strong></td>
                    <td><?= e($event->getSport()) ?></td>
                    <td>
                        <?= e($event->getEventDate()->format('d M')) ?>,
                        <?= e(hhmm($event->getStartTime())) ?>&ndash;<?= e(hhmm($event->getEndTime())) ?>
                    </td>
                    <td class="muted"><?= e((string) $event->getMaxParticipants()) ?> max</td>
                    <td><a class="btn ghost small" href="<?= e(url('event', 'show', ['id' => $event->getEventId()])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (Auth::check()): ?>
    <a class="btn" href="<?= e(url('event', 'create', ['facilityId' => $facility->getFacilityId()])) ?>">
        Create an event here
    </a>
<?php else: ?>
    <a class="btn ghost" href="<?= e(url('auth')) ?>">Sign in to organise a game here</a>
<?php endif; ?>
