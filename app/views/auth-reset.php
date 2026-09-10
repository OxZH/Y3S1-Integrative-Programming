<?php
// Choose a new password after following a reset link. Author: Ivan Lim Tze Yang

/** @var string $token */
/** @var array<string,string> $errors */

use App\Security\PasswordPolicy;

$bad = static fn (string $f): string => isset($errors[$f]) ? 'bad' : '';
$err = static fn (string $f): string => isset($errors[$f])
    ? '<div class="field-error">' . e($errors[$f]) . '</div>'
    : '';
?>
<div class="auth-page">

<h1>Choose a new password</h1>
<p class="lede">This link works once. After you set a password it stops working.</p>

<?php if ($errors !== []): ?>
    <div class="flash error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth', 'reset')) ?>" class="card auth-card">
    <?= $csrfField ?>

    <!-- Carried in the form body, not the address bar, so it does not end up in
         browser history, a bookmark or a server access log. -->
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <label for="password">New password</label>
    <input class="<?= e($bad('password')) ?>" type="password" id="password" name="password"
           autocomplete="new-password" required autofocus>
    <?= $err('password') ?>

    <label for="passwordConfirm">Confirm new password</label>
    <input class="<?= e($bad('passwordConfirm')) ?>" type="password" id="passwordConfirm"
           name="passwordConfirm" autocomplete="new-password" required>
    <?= $err('passwordConfirm') ?>

    <p class="small muted">
        At least <?= (int) PasswordPolicy::MIN_LENGTH ?> characters, using three of:
        lower case, upper case, a digit, a symbol.
    </p>

    <div class="form-actions">
        <button class="btn" type="submit">Set new password</button>
        <a class="btn ghost" href="<?= e(url('auth')) ?>">Cancel</a>
    </div>
</form>

</div>
