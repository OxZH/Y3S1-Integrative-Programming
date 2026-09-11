<?php
// My participation page. Author: Ng Jing Siang

use App\Model\EventRegistration;

/** @var EventRegistration[] $registrations */
?>
<h1>My participation</h1>
<p class="lede">Games you have joined, are attending, or attended in the past.</p>

<?php if ($registrations === []): ?>
    <div class="card empty">
        <p>You have not joined a game yet.</p>
        <a class="btn" href="<?= e(url('discovery')) ?>">Find a game</a>
    </div>
<?php else: ?>
    <div class="card card-table">
        <table>
            <thead>
            <tr><th>Game</th><th>Date</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($registrations as $registration): ?>
                <?php $event = $registration->getEvent(); ?>
                <tr>
                    <td>
                        <strong><?= e($event?->getName() ?? 'Unknown game') ?></strong>
                        <div class="small muted"><?= e($event?->getSport() ?? '') ?></div>
                    </td>
                    <td class="small">
                        <?= $event !== null ? e($event->getEventDate()->format('D, d M Y')) : '' ?>
                    </td>
                    <td>
                        <?php
                        $status = $registration->getStatus();
                        $pill   = match (true) {
                            $status->isActive() => 'live',
                            $status->value === 'CANCELLED', $status->value === 'NO_SHOW' => 'dead',
                            default => 'wait',
                        };
                        ?>
                        <span class="pill <?= e($pill) ?>"><?= e($status->label()) ?></span>
                    </td>
                    <td class="actions">
                        <?php if ($event !== null): ?>
                            <a class="btn ghost small" href="<?= e(url('event', 'show', ['id' => $event->getEventId()])) ?>">View</a>
                        <?php endif; ?>
                        <?php if ($status->isActive() && $event !== null): ?>
                            <form method="post" action="<?= e(url('discovery', 'leave')) ?>" class="inline-form"
                                  data-confirm="Leave this game?">
                                <?= $csrfField ?>
                                <input type="hidden" name="eventRegistrationId" value="<?= e((string) $registration->getEventRegistrationId()) ?>">
                                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                                <button class="btn ghost small" type="submit">Leave</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
