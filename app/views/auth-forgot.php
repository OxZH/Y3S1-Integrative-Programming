<?php
// Password reset request form. Author: Ivan Lim Tze Yang

/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */
/** @var bool $sent */
/** @var string|null $demoLink */

use App\Security\PasswordPolicy;
?>
<h1>Reset your password</h1>

<?php if ($sent): ?>
    <div class="card narrow-field">
        <p class="lede">Check your email.</p>
        <p>
            If that address has an account, a reset link is on its way to it. The link
            works once and expires in <?= (int) PasswordPolicy::RESET_TTL_MINUTES ?> minutes.
        </p>
        <p class="small muted">
            We do not say whether the address is registered. Answering that would turn
            this form into a way of finding out who has an account here.
        </p>

        <?php if ($demoLink !== null): ?>
            <div class="banner">
                <strong>Demo only.</strong> There is no mail server in this project, so the
                link is shown here and also appended to <span class="mono">storage/mail.log</span>.
                On a real build it goes to the inbox and nowhere else.
                <p class="spaced-top"><a href="<?= e($demoLink) ?>">Open the reset link</a></p>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <a class="btn ghost" href="<?= e(url('auth')) ?>">Back to sign in</a>
        </div>
    </div>
<?php else: ?>
    <p class="lede">We will send a single-use link to the address on the account.</p>

    <form method="post" action="<?= e(url('auth', 'sendReset')) ?>" class="card narrow-field">
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
