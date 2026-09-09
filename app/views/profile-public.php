<?php
// Profile page with the participation history. Author: Ivan Lim Tze Yang

/** @var \App\Model\Account $account */
/** @var bool $isSelf */
/** @var string|null $connectionState */
/** @var array<int,array<string,mixed>> $history */
/** @var string|null $lastLoginAt */
/** @var \App\Model\AuthEvent[] $recentEvents */

use App\Model\Admin;
use App\Model\FacilityOwner;
use App\Model\User;
?>
<div class="page-head">
    <div>
        <h1><?= e($account->getUsername()) ?></h1>
        <p class="lede-flush">
            <span class="pill live"><?= e($account->getUserType()->label()) ?></span>
            <span class="pill <?= $account->isActive() ? 'live' : 'dead' ?>">
                <?= e($account->getAccountStatus()->label()) ?>
            </span>
        </p>
    </div>
    <?php if (!$isSelf): ?>
        <form method="post" action="<?= e(url('profile', 'sendFriendRequest')) ?>">
            <?= $csrfField ?? '' ?>
            <input type="hidden" name="userId" value="<?= e($account->getBaseUserId()) ?>">

            <?php if ($connectionState === 'PENDING'): ?>
                <button class="btn ghost" type="submit" disabled>Friend request pending</button>
            <?php elseif ($connectionState === 'ACCEPTED'): ?>
                <button class="btn ghost" type="submit" disabled>Friends</button>
            <?php else: ?>
                <button class="btn" type="submit">Add friend</button>
            <?php endif; ?>

        </form>
    <?php endif; ?>
    <?php if ($isSelf): ?>
        <div class="toolbar">
            <a class="btn" href="<?= e(url('profile', 'edit')) ?>">Edit profile</a>
            <a class="btn ghost" href="<?= e(url('profile', 'security')) ?>">Password &amp; security</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($isSelf && $lastLoginAt !== null): ?>
    <div class="banner">
        Last signed in <strong><?= e($lastLoginAt) ?></strong>.
        If that was not you, change your password.
    </div>
<?php endif; ?>

<div class="row">
    <div class="card">
        <h2>Account</h2>
        <?php if ($account->getEmail() && $account->getContactNumber()): ?>
            <p class="stat"><span class="sub-tight">Email</span><br><?= e($account->getEmail()) ?></p>
            <p class="stat"><span class="sub-tight">Contact number</span><br><?= e($account->getContactNumber()) ?></p>
        <?php endif; ?>

        <p class="stat"><span class="sub-tight">Member since</span><br>
            <?= e($account->getRegisterTime()?->format('j M Y') ?? 'Unknown') ?>
        </p>

        <?php if ($account instanceof User): ?>
            <p class="stat"><span class="sub-tight">Favourite sport</span><br>
                <?= e($account->getFavoriteSport() ?? 'Not set') ?>
            </p>
            <p class="stat"><span class="sub-tight">Plays around</span><br>
                <?= e($account->getLocation() ?? 'Not set') ?>
            </p>
            <p class="stat"><span class="sub-tight">Age</span><br>
                <?= $account->getAge() !== null ? (int) $account->getAge() . ' years' : 'Not set' ?>
            </p>
        <?php endif; ?>

        <?php if ($account instanceof FacilityOwner): ?>
            <p class="stat"><span class="sub-tight">Bank</span><br><?= e($account->getBankName()) ?></p>
            <p class="stat"><span class="sub-tight">Bank account</span><br>
                <span class="mono"><?= e($account->getMaskedBankAccountNum()) ?></span>
            </p>
            <p class="stat"><span class="sub-tight">Business registration</span><br>
                <?= e($account->getBusinessRegNum()) ?>
            </p>
            <p class="small muted">
                The account number is shown masked. The full value is never sent to the
                browser and never leaves the server except to the payment module.
            </p>
        <?php endif; ?>

        <?php if ($account instanceof Admin): ?>
            <p class="stat"><span class="sub-tight">Staff number</span><br>
                <span class="mono"><?= e($account->getAdminId()) ?></span>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($account instanceof User): ?>
        <div class="card">
            <h2>Recently participated</h2>
            <p class="small muted">
                The event names below come from the Event &amp; Facility module's
                <span class="mono">getEventDetails</span> web service, not from this
                module's own tables.
            </p>

            <?php if ($history === []): ?>
                <p class="empty">No events joined yet.</p>
            <?php else: ?>
                <table class="card-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Sport</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td>
                                    <?= e((string) $row['name']) ?>
                                    <?php if ($row['available'] !== true): ?>
                                        <span class="pill wait">unavailable</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $row['sport']) ?></td>
                                <td><?= e((string) $row['eventDate']) ?></td>
                                <td><span class="pill"><?= e((string) $row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($isSelf && $recentEvents !== []): ?>
    <div class="card">
        <h2>Recent account activity</h2>
        <p class="small muted">
            Every sign-in, password change and refused attempt on this account.
            <a href="<?= e(url('profile', 'security')) ?>">See the full history</a>.
        </p>
        <table class="card-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Event</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentEvents as $event): ?>
                    <tr>
                        <td class="mono small"><?= e($event->getOccurredAt()?->format('Y-m-d H:i') ?? '-') ?></td>
                        <td><?= e($event->getEventType()->label()) ?></td>
                        <td>
                            <span class="pill <?= $event->succeeded() ? 'live' : 'dead' ?>">
                                <?= $event->succeeded() ? 'ok' : 'refused' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>