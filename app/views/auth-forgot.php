<?php
// Password reset request form. Author: Ivan Lim Tze Yang

/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */
/** @var bool $sent */
/** @var string|null $fallbackLink */

use App\Security\PasswordPolicy;
?>
<div class="auth-page">

<h1>Reset your password</h1>

<?php if ($sent): ?>
    <div class="card auth-card">
        <p class="lede">Check your email.</p>
        <p>
            If that address has an account, a reset link is on its way to it. The link
            works once and expires in <?= (int) PasswordPolicy::RESET_TTL_MINUTES ?> minutes.
        </p>
        <?php // Only when this copy of the site has no mail server configured -
              // a teammate who has not set up .env would otherwise have no way
              // through the flow at all. With mail working the link is never
              // put on screen: it goes to the inbox and nowhere else. ?>
        <?php if ($fallbackLink !== null): ?>
            <div class="banner">
                No mail server is set up on this machine, so the link is shown here
                and written to <span class="mono">storage/mail.log</span>.
                <p class="spaced-top"><a href="<?= e($fallbackLink) ?>">Open the reset link</a></p>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <a class="btn ghost" href="<?= e(url('auth')) ?>">Back to sign in</a>
        </div>
    </div>
<?php else: ?>
    <p class="lede">We will send a single-use link to the address on the account.</p>

    <form method="post" action="<?= e(url('auth', 'sendReset')) ?>" class="card auth-card">
        <?= $csrfField ?>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" maxlength="255"
               value="<?= old($input, 'email') ?>" required autofocus>

        <div class="form-actions">
            <button class="btn" type="submit">Send reset link</button>
            <a class="btn ghost" href="<?= e(url('auth')) ?>">Cancel</a>
        </div>
    </form>
<?php endif; ?>

</div>
