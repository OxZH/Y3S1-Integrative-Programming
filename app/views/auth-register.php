<?php
// Registration form. Author: Ivan Lim Tze Yang

/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */

use App\Security\PasswordPolicy;
use App\UserType;

$bad = static fn (string $f): string => isset($errors[$f]) ? 'bad' : '';
$err = static fn (string $f): string => isset($errors[$f])
    ? '<div class="field-error">' . e($errors[$f]) . '</div>'
    : '';

$chosenType = is_string($input['userType'] ?? null) ? $input['userType'] : UserType::USER->value;
?>
<h1>Create an account</h1>
<p class="lede">Players organise and join games. Facility owners list venues.</p>

<?php if ($errors !== []): ?>
    <div class="flash error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth', 'store')) ?>" class="card">
    <?= $csrfField ?>

    <label for="userType">Account type</label>
    <select class="<?= e($bad('userType')) ?>" id="userType" name="userType" data-role-toggle>
        <?php foreach (UserType::registrable() as $type): ?>
            <option value="<?= e($type->value) ?>" <?= $chosenType === $type->value ? 'selected' : '' ?>>
                <?= e($type->label()) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?= $err('userType') ?>
    <p class="small muted">Administrator accounts are not created here.</p>

    <div class="row">
        <div>
            <label for="username">Username</label>
            <input class="<?= e($bad('username')) ?>" type="text" id="username" name="username"
                   maxlength="50" value="<?= old($input, 'username') ?>" required>
            <?= $err('username') ?>
        </div>
        <div>
            <label for="contactNumber">Contact number</label>
            <input class="<?= e($bad('contactNumber')) ?>" type="tel" id="contactNumber" name="contactNumber"
                   maxlength="20" placeholder="0121234567" value="<?= old($input, 'contactNumber') ?>" required>
            <?= $err('contactNumber') ?>
        </div>
    </div>

    <label for="email">Email</label>
    <input class="<?= e($bad('email')) ?>" type="email" id="email" name="email" maxlength="255"
           autocomplete="username" value="<?= old($input, 'email') ?>" required>
    <?= $err('email') ?>

    <div class="row">
        <div>
            <label for="password">Password</label>
            <input class="<?= e($bad('password')) ?>" type="password" id="password" name="password"
                   autocomplete="new-password" required>
            <?= $err('password') ?>
        </div>
        <div>
            <label for="passwordConfirm">Confirm password</label>
            <input class="<?= e($bad('passwordConfirm')) ?>" type="password" id="passwordConfirm"
                   name="passwordConfirm" autocomplete="new-password" required>
            <?= $err('passwordConfirm') ?>
        </div>
    </div>
    <p class="small muted">
        At least <?= (int) PasswordPolicy::MIN_LENGTH ?> characters, using three of:
        lower case, upper case, a digit, a symbol. It must not contain your name or email.
    </p>

    <!-- Player fields -->
    <div data-role-fields="<?= e(UserType::USER->value) ?>">
        <h2 class="sub">Your profile</h2>
        <div class="row-3">
            <div>
                <label for="favoriteSport">Favourite sport</label>
                <input class="<?= e($bad('favoriteSport')) ?>" type="text" id="favoriteSport"
                       name="favoriteSport" maxlength="50" placeholder="Badminton"
                       value="<?= old($input, 'favoriteSport') ?>">
                <?= $err('favoriteSport') ?>
            </div>
            <div>
                <label for="location">Where you play</label>
                <input class="<?= e($bad('location')) ?>" type="text" id="location" name="location"
                       maxlength="255" placeholder="Setapak, Kuala Lumpur"
                       value="<?= old($input, 'location') ?>">
                <?= $err('location') ?>
            </div>
            <div>
                <label for="birthDate">Date of birth</label>
                <input class="<?= e($bad('birthDate')) ?>" type="date" id="birthDate" name="birthDate"
                       value="<?= old($input, 'birthDate') ?>">
                <?= $err('birthDate') ?>
            </div>
        </div>
    </div>

    <!-- Facility owner fields -->
    <div data-role-fields="<?= e(UserType::FACILITY_OWNER->value) ?>">
        <h2 class="sub">Payout details</h2>
        <p class="small muted">Needed so booking payments can reach you.</p>
        <div class="row-3">
            <div>
                <label for="bankName">Bank</label>
                <input class="<?= e($bad('bankName')) ?>" type="text" id="bankName" name="bankName"
                       maxlength="100" placeholder="Maybank" value="<?= old($input, 'bankName') ?>">
                <?= $err('bankName') ?>
            </div>
            <div>
                <label for="bankAccountNum">Bank account number</label>
                <input class="<?= e($bad('bankAccountNum')) ?>" type="text" id="bankAccountNum"
                       name="bankAccountNum" maxlength="50" autocomplete="off"
                       value="<?= old($input, 'bankAccountNum') ?>">
                <?= $err('bankAccountNum') ?>
            </div>
            <div>
                <label for="businessRegNum">Business registration no.</label>
                <input class="<?= e($bad('businessRegNum')) ?>" type="text" id="businessRegNum"
                       name="businessRegNum" maxlength="50" placeholder="SSM-202401001234"
                       value="<?= old($input, 'businessRegNum') ?>">
                <?= $err('businessRegNum') ?>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Create account</button>
        <a class="btn ghost" href="<?= e(url('auth')) ?>">I already have one</a>
    </div>
</form>
