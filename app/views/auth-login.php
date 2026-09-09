<?php
// Sign-in form. Author: Ivan Lim Tze Yang

/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */

use App\Security\PasswordPolicy;

$bad = static fn (string $f): string => isset($errors[$f]) ? 'bad' : '';
$err = static fn (string $f): string => isset($errors[$f])
    ? '<div class="field-error">' . e($errors[$f]) . '</div>'
    : '';
?>
<h1>Sign in</h1>
<p class="lede">Use the email address you registered with.</p>

<form method="post" action="<?= e(url('auth', 'login')) ?>" class="card narrow-field">
    <?= $csrfField ?>

    <label for="email">Email</label>
    <input class="<?= e($bad('email')) ?>" type="email" id="email" name="email" maxlength="255"
           autocomplete="username" value="<?= old($input, 'email') ?>" required autofocus>
    <?= $err('email') ?>

    <label for="password">Password</label>
    <input class="<?= e($bad('password')) ?>" type="password" id="password" name="password"
           autocomplete="current-password" required>
    <?= $err('password') ?>

    <div class="form-actions">
        <button class="btn" type="submit">Sign in</button>
        <a class="btn ghost" href="<?= e(url('auth', 'forgot')) ?>">Forgot password</a>
    </div>

    <p class="small muted spaced-top">
        No account yet? <a href="<?= e(url('auth', 'register')) ?>">Create one</a>.
    </p>
</form>

<div class="card card-inset">
    <p class="small muted flush">
        A wrong email and a wrong password give the same message on purpose, so this
        form cannot be used to find out which addresses are registered.
        After <?= (int) PasswordPolicy::MAX_ATTEMPTS ?> failed attempts the account
        locks for <?= (int) PasswordPolicy::LOCKOUT_MINUTES ?> minutes.
    </p>
</div>

<?php if (config('app.debug')): ?>
    <div class="banner">
        <strong>Demo accounts</strong> &mdash; password <span class="mono">Password123!</span><br>
        <span class="small">
            aisyah.rahman@example.com (player) &middot;
            contact@smashpoint.my (facility owner) &middot;
            admin@sportsplatform.my (administrator)
        </span>
    </div>
<?php endif; ?>
