<?php
// Events organised by the signed-in user. Author: Goh Jian Yu
// Receives: $events
?>
<h1>My events</h1>
<p class="lede">Everything you have organised, in every state.</p>

<div class="toolbar">
    <a class="btn" href="<?= e(url('event', 'create')) ?>">Create an event</a>
</div>

<?php if ($events === []): ?>
    <div class="card empty">
        <p>You have not organised a game yet.</p>
        <a class="btn" href="<?= e(url('event', 'create')) ?>">Create an event</a>
    </div>
<?php else: ?>
    <div class="card card-table">
        <table>
            <thead>
            <tr><th>Event</th><th>When</th><th>Venue</th><th>Visibility</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <?php
                $status = $event->getStatus();
                $statusClass = '';

                if ($status->isPublished() || $status->value === 'ONGOING') {
                    $statusClass = 'live';
                } else if ($status->value === 'DRAFT' || $status->value === 'PENDING_PAYMENT') {
                    $statusClass = 'wait';
                } else if ($status->isCancelled()) {
                    $statusClass = 'dead';
                }

                // An event stays a draft until the venue is booked and paid for.
                // If the organiser closed the tab partway through, this is the
                // way back in - otherwise the event is stuck with no route to
                // finish it.
                $needsPayment = $status->value === 'DRAFT' || $status->value === 'PENDING_PAYMENT';
                ?>
                <tr>
                    <td>
                        <strong><?= e($event->getName()) ?></strong>
                        <div class="small muted"><?= e($event->getSport()) ?></div>
                    </td>
                    <td class="small">
                        <?= e($event->getEventDate()->format('d M Y')) ?><br>
                        <?= e(hhmm($event->getStartTime())) ?>&ndash;<?= e(hhmm($event->getEndTime())) ?>
                    </td>
                    <td class="small"><?= e($event->getLocation()?->getName() ?? '—') ?></td>
                    <td class="small"><?= e($event->getVisibility()->label()) ?></td>
                    <td><span class="pill <?= e($statusClass) ?>"><?= e($status->label()) ?></span></td>
                    <td class="actions">
                        <a class="btn ghost small" href="<?= e(url('event', 'show', ['id' => $event->getEventId()])) ?>">Open</a>
                        <a class="btn ghost small" href="<?= e(url('event', 'invites', ['id' => $event->getEventId()])) ?>">Links</a>

                        <?php if ($needsPayment): ?>
                            <a class="btn small"
                               href="payment.php?action=venue&amp;eventId=<?= e(urlencode((string) $event->getEventId())) ?>">
                                Pay venue
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
