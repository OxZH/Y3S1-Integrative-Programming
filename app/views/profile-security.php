<?php
// Password change, account deactivation and the audit trail. Author: Ivan Lim Tze Yang

/** @var \App\Model\Account $account */
/** @var \App\Model\AuthEvent[] $events */
/** @var array<string,string> $errors */

use App\Security\PasswordPolicy;

$bad = static fn (string $f): string => isset($errors[$f]) ? 'bad' : '';
$err = static fn (string $f): string => isset($errors[$f])
    ? '<div class="field-error">' . e($errors[$f]) . '</div>'
    : '';
?>
<h1>Password &amp; security</h1>
<p class="lede">Signed in as <strong><?= e($account->getUsername()) ?></strong>.</p>

<?php if ($errors !== []): ?>
    <div class="flash error">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="row">
    <form method="post" action="<?= e(url('profile', 'changePassword')) ?>" class="card">
        <?= $csrfField ?>
        <h2>Change password</h2>

        <label for="currentPassword">Current password</label>
        <input class="<?= e($bad('currentPassword')) ?>" type="password" id="currentPassword"
               name="currentPassword" autocomplete="current-password" required>
        <?= $err('currentPassword') ?>
        <p class="small muted">
            Asked for even though you are already signed in, so a session left open on a
            shared machine is not enough to take the account.
        </p>

        <label for="newPassword">New password</label>
        <input class="<?= e($bad('newPassword')) ?>" type="password" id="newPassword"
               name="newPassword" autocomplete="new-password" required>
        <?= $err('newPassword') ?>

        <label for="newPasswordConfirm">Confirm new password</label>
        <input class="<?= e($bad('newPasswordConfirm')) ?>" type="password" id="newPasswordConfirm"
               name="newPasswordConfirm" autocomplete="new-password" required>
        <?= $err('newPasswordConfirm') ?>

        <p class="small muted">
            At least <?= (int) PasswordPolicy::MIN_LENGTH ?> characters, using three of:
            lower case, upper case, a digit, a symbol.
            <?php if ($account->getPasswordChangedAt() !== null): ?>
                Last changed <?= e($account->getPasswordChangedAt()->format('j M Y')) ?>.
            <?php endif; ?>
        </p>

        <div class="form-actions">
            <button class="btn" type="submit">Change password</button>
        </div>
    </form>

    <form method="post" action="<?= e(url('profile', 'deactivate')) ?>" class="card"
          data-confirm="Deactivate your account? You will be signed out immediately.">
        <?= $csrfField ?>
        <h2>Deactivate account</h2>
        <p>
            Your events and reviews stay where they are, but you will not be able to sign
            in and your account stops appearing to other people. Support can undo it.
        </p>

        <label for="deactivatePassword">Confirm with your password</label>
        <input type="password" id="deactivatePassword" name="currentPassword"
               autocomplete="current-password" required>

        <div class="form-actions">
            <button class="btn danger" type="submit">Deactivate my account</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Account activity</h2>
    <p class="small muted">
        Every sign-in, failed sign-in, password change and refused attempt on this account.
        No password, reset link or session id is ever written here.
    </p>

    <?php if ($events === []): ?>
        <p class="empty">Nothing recorded yet.</p>
    <?php else: ?>
        <table class="card-table">
            <thead>
                <tr><th>When</th><th>Event</th><th>Result</th><th>From</th><th>Detail</th></tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td class="mono small"><?= e($event->getOccurredAt()?->format('Y-m-d H:i:s') ?? '-') ?></td>
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
