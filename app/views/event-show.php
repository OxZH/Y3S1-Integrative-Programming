<?php
// Event detail and organiser controls. Author: Goh Jian Yu
// Receives: $event, $participants, $isHost, $blocker, $canDelete
// Receives: $myRegistration, $playerPage - js part

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

<?php
// js part - Discovery & Event Matchmaking owns joining, and this is the page a
// player is on when they decide, so its one button lives here.
//
// The button follows the viewer's registration, not their role. An organiser is
// usually playing too, and hiding this from them would strand anyone who is
// both: they would sit in the player list and the headcount with no way out.
// Hosting a game and being in it are separate facts.
//
// What the button does is still Discovery's to authorise - it re-checks
// visibility and capacity on submit, so nothing here is trusted as permission.
?>
<?php if ($status->isPublished() || $status->value === 'ONGOING'): ?>
    <div class="card toolbar toolbar-flush">
        <?php if (!App\Security\Auth::check()): ?>
            <a class="btn" href="<?= e(url('auth')) ?>">Sign in to join this game</a>
        <?php elseif ($isHost): ?>
            <?php
            // PaymentFacade refuses to register the organiser as a participant,
            // so offering them the button only produced an error every time.
            // The count beside it is the players who joined, which does not
            // include the organiser.
            ?>
            <span class="small muted">
                You are organising this game, so you are not listed among the players who joined.
            </span>
        <?php elseif ($myRegistration !== null): ?>
            <form method="post" action="<?= e(url('discovery', 'leave')) ?>" class="inline-form"
                  data-confirm="Leave this game? Your spot goes back to whoever wants it.">
                <?= $csrfField ?>
                <input type="hidden" name="eventRegistrationId"
                       value="<?= e($myRegistration->getEventRegistrationId()) ?>">
                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                <button class="btn ghost" type="submit">Leave this game</button>
            </form>
            <span class="small muted">You are in this game.</span>
        <?php elseif ($participants >= $event->getMaxParticipants()): ?>
            <span class="small muted">This game is full.</span>
        <?php else: ?>
            <?php
            // Joining is a commitment to turn up, and to a fee when there is
            // one, so the amount is named in the prompt rather than left for
            // the player to remember from the panel above.
            $joinPrompt = $event->getFeePerParticipant() > 0
                ? sprintf(
                    'Join this game? The fee is %s per player, and you are counted in from now on.',
                    money($event->getFeePerParticipant())
                )
                : 'Join this game? You are counted in from now on, and the organiser will see you have joined.';
            ?>
            <form method="post" action="<?= e(url('discovery', 'join')) ?>" class="inline-form"
                  data-confirm="<?= e($joinPrompt) ?>">
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                <button class="btn" type="submit">Join this game</button>
            </form>
            <span class="small muted">
                <?= e((string) ($event->getMaxParticipants() - $participants)) ?> spot(s) left.
            </span>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php
// Who is in the game. The rows belong to Discovery & Event Matchmaking, so the
// controller asks that module for one page of them rather than counting here.
// Ten to a page: enough for a full side, short enough to read at a glance.
?>
<?php if ($playerPage['total'] > 0): ?>
    <h2>Players</h2>

    <div class="card">
        <table>
            <thead>
                <tr><th>#</th><th>Player</th><th>Joined</th></tr>
            </thead>
            <tbody>
                <?php $rank = ($playerPage['page'] - 1) * App\Domain\DiscoveryFacade::PLAYERS_PER_PAGE; ?>
                <?php foreach ($playerPage['players'] as $registration): ?>
                    <?php $player = $registration->getUser(); ?>
                    <tr>
                        <td><?= e((string) ++$rank) ?></td>
                        <td>
                            <?= e($player?->getUsername() ?? 'A player') ?>
                            <?php if ($myRegistration !== null
                                && $registration->getUserId() === $myRegistration->getUserId()): ?>
                                <span class="pill live">You</span>
                            <?php endif; ?>
                        </td>
                        <td class="small muted">
                            <?= e($registration->getRegisterTime()->format('D, d M Y')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($playerPage['pages'] > 1): ?>
            <div class="toolbar toolbar-flush spaced-top">
                <?php if ($playerPage['page'] > 1): ?>
                    <a class="btn ghost small" href="<?= e(url('event', 'show', [
                        'id'      => $event->getEventId(),
                        'players' => $playerPage['page'] - 1,
                    ])) ?>">Previous</a>
                <?php endif; ?>

                <span class="small muted">
                    Page <?= e((string) $playerPage['page']) ?> of <?= e((string) $playerPage['pages']) ?>
                    &middot; <?= e((string) $playerPage['total']) ?> player(s)
                </span>

                <?php if ($playerPage['page'] < $playerPage['pages']): ?>
                    <a class="btn ghost small" href="<?= e(url('event', 'show', [
                        'id'      => $event->getEventId(),
                        'players' => $playerPage['page'] + 1,
                    ])) ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php // end js part ?>

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

        <?php if (
            in_array($status->value, ['PUBLISHED', 'FULL', 'ONGOING'], true)
            && $event->isInThePast()
        ): ?>
            <form method="post" action="<?= e(url('event', 'complete')) ?>" class="inline-form">
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                <button class="btn" type="submit">Complete and settle participant fees</button>
            </form>
        <?php endif; ?>

        <a class="btn ghost" href="<?= e(url('event', 'invites', ['id' => $event->getEventId()])) ?>">Manage invite links</a>

        <?php
        // One destructive button, not two. Delete removes the row, but only
        // when there is nothing worth keeping; once the venue is paid for or
        // somebody has joined, cancelling is the only honest thing on offer,
        // so that is the button shown instead.
        ?>
        <?php if ($canDelete): ?>
            <form method="post" action="<?= e(url('event', 'delete')) ?>" class="inline-form"
                  data-confirm="Delete this event? Nothing is booked against it, so the record goes for good.">
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                <button class="btn danger" type="submit">Delete event</button>
            </form>
        <?php elseif (!$status->isCancelled() && $status->value !== 'COMPLETED'): ?>
            <form method="post" action="<?= e(url('event', 'cancel')) ?>" class="inline-form"
                  data-confirm="Cancel this event? Anyone who joined will see that it is off.">
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                <button class="btn danger" type="submit">Cancel event</button>
            </form>
        <?php endif; ?>
    </div>

    <p class="small muted">
        Publishing asks the Venue Booking module whether this event's booking is confirmed and paid.
        Until it answers yes, the event stays hidden from other players.
    </p>
<?php endif; ?>
