<?php
// Registration form. Author: Ivan Lim Tze Yang

/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */

use App\Bank;
use App\Security\PasswordPolicy;
use App\Sport;
use App\UserType;

$bad = static fn (string $f): string => isset($errors[$f]) ? 'bad' : '';
$err = static fn (string $f): string => isset($errors[$f])
    ? '<div class="field-error">' . e($errors[$f]) . '</div>'
    : '';

$chosenType = is_string($input['userType'] ?? null) ? $input['userType'] : UserType::USER->value;

// Kept after a failed save, so the ticked sports come back ticked.
$chosenSports = is_array($input['favoriteSports'] ?? null) ? $input['favoriteSports'] : [];

// The date box will not offer a day that would make the account holder younger
// than this. The server checks it again in Validator::minimumAge().
$latestBirthDate = (new DateTimeImmutable('today'))->modify('-3 years')->format('Y-m-d');
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
            <div class="password-field">
                <input class="<?= e($bad('password')) ?>" type="password" id="password" name="password"
                       autocomplete="new-password" required data-no-copy>
                <button class="btn ghost small reveal-btn" type="button"
                        data-reveal-password="password" aria-pressed="false">Hold to show</button>
            </div>
            <?= $err('password') ?>
        </div>
        <div>
            <label for="passwordConfirm">Confirm password</label>
            <div class="password-field">
                <input class="<?= e($bad('passwordConfirm')) ?>" type="password" id="passwordConfirm"
                       name="passwordConfirm" autocomplete="new-password" required data-no-copy>
                <button class="btn ghost small reveal-btn" type="button"
                        data-reveal-password="passwordConfirm" aria-pressed="false">Hold to show</button>
            </div>
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
        <label for="favoriteSports">Favourite sports</label>
        <select class="<?= e($bad('favoriteSports')) ?>" id="favoriteSports" name="favoriteSports[]"
                multiple size="8">
            <?php foreach (Sport::cases() as $sport): ?>
                <option value="<?= e($sport->value) ?>"
                    <?= in_array($sport->value, $chosenSports, true) ? 'selected' : '' ?>>
                    <?= e($sport->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?= $err('favoriteSports') ?>
        <p class="small muted">Hold Ctrl (or Cmd) to pick more than one.</p>

        <div class="row">
            <div>
                <!-- A fuller address geocodes to a better position, and position is
                     what the discovery module sorts and recommends games by -->
                <label for="location">Location</label>
                <input class="<?= e($bad('location')) ?>" type="text" id="location" name="location"
                       maxlength="255" placeholder="77, Lorong Lembah Permai 3, 11200 Tanjung Bungah, Pulau Pinang"
                       value="<?= old($input, 'location') ?>"
                       data-location-lookup="<?= e(url('location', 'lookup')) ?>">
                <!-- Filled in by app.js once the address has been looked up. -->
                <div class="small" id="locationConfirm" hidden></div>
                <p class="small muted">
                    Your address, or just the area you play in. Others only see how far
                    away you are, never where you are.
                </p>
                <?= $err('location') ?>
            </div>
            <div>
                <label for="birthDate">Date of birth</label>
                <input class="<?= e($bad('birthDate')) ?>" type="date" id="birthDate" name="birthDate"
                       max="<?= e($latestBirthDate) ?>" value="<?= old($input, 'birthDate') ?>">
                <?= $err('birthDate') ?>
                <p class="small muted">You must be at least 3 years old.</p>
            </div>
        </div>
    </div>

    <!-- Facility owner fields -->
    <div data-role-fields="<?= e(UserType::FACILITY_OWNER->value) ?>">
        <h2 class="sub">Payout details</h2>
        <p class="small muted">Needed so booking payments can reach you.</p>
        <?php $chosenBank = is_string($input['bankName'] ?? null) ? $input['bankName'] : ''; ?>

        <div class="row">
            <div>
                <label for="bankName">Bank</label>
                <select class="<?= e($bad('bankName')) ?>" id="bankName" name="bankName">
                    <option value="">Choose your bank</option>
                    <?php foreach (Bank::cases() as $bank): ?>
                        <option value="<?= e($bank->value) ?>" <?= $chosenBank === $bank->value ? 'selected' : '' ?>>
                            <?= e($bank->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $err('bankName') ?>
            </div>
            <div>
                <label for="bankAccountNum">Bank account number</label>
                <input class="<?= e($bad('bankAccountNum')) ?>" type="text" id="bankAccountNum"
                       name="bankAccountNum" maxlength="50" autocomplete="off"
                       value="<?= old($input, 'bankAccountNum') ?>">
                <?= $err('bankAccountNum') ?>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Create account</button>
        <a class="btn ghost" href="<?= e(url('auth')) ?>">I already have one</a>
    </div>
</form>
