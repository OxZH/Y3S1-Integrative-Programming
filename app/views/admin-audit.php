<?php
// System-wide security log. Author: Ivan Lim Tze Yang

/** @var \App\Model\AuthEvent[] $events */
/** @var \App\AuthEventType|null $filter */

use App\AuthEventType;
?>
<div class="page-head">
    <div>
        <h1>Security log</h1>
        <p class="lede-flush">The most recent <?= count($events) ?> events.</p>
    </div>
    <div class="toolbar">
        <a class="btn ghost" href="<?= e(url('admin', 'accounts')) ?>">Accounts</a>
    </div>
</div>

<div class="card">
    <div class="chip-list">
        <a class="pill <?= $filter === null ? 'live' : '' ?>" href="<?= e(url('admin', 'audit')) ?>">All</a>
        <?php foreach ([AuthEventType::LOGIN_FAILED, AuthEventType::LOGIN_SUCCESS,
                        AuthEventType::ACCOUNT_LOCKED, AuthEventType::ACCESS_DENIED,
                        AuthEventType::PASSWORD_CHANGED, AuthEventType::PASSWORD_RESET_COMPLETED] as $type): ?>
            <a class="pill <?= $filter === $type ? 'live' : '' ?>"
               href="<?= e(url('admin', 'audit', ['type' => $type->value])) ?>">
                <?= e($type->label()) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($events === []): ?>
        <p class="empty">Nothing recorded for that filter.</p>
    <?php else: ?>
        <table class="card-table">
            <thead>
                <tr><th>When</th><th>Account</th><th>Event</th><th>Result</th><th>From</th><th>Detail</th></tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td class="mono small"><?= e($event->getOccurredAt()?->format('Y-m-d H:i:s') ?? '-') ?></td>
                        <td class="small">
                            <?php if ($event->getUsername() !== null): ?>
                                <?= e($event->getUsername()) ?>
                            <?php elseif ($event->getMaskedEmailTried() !== null): ?>
                                <span class="mono"><?= e($event->getMaskedEmailTried()) ?></span>
                                <span class="muted">(no account)</span>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($event->getEventType()->label()) ?></td>
                        <td>
                            <span class="pill <?= $event->succeeded() ? 'live' : 'dead' ?>">
                                <?= $event->succeeded() ? 'ok' : 'refused' ?>
                            </span>
                        </td>
                        <td class="mono small"><?= e($event->getIpAddress() ?? '-') ?></td>
                        <td class="small"><?= e($event->getDetail() ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
