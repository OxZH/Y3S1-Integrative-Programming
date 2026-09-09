<?php
// Profile edit form. Author: Ivan Lim Tze Yang

/** @var \App\Model\Account $account */
/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */

use App\Model\FacilityOwner;
use App\Model\User;

$bad = static fn (string $f): string => isset($errors[$f]) ? 'bad' : '';
$err = static fn (string $f): string => isset($errors[$f])
    ? '<div class="field-error">' . e($errors[$f]) . '</div>'
    : '';

$value = static function (string $field, ?string $stored) use ($input): string {
    return old($input, $field, $stored ?? '');
};
?>
<h1>Edit profile</h1>
<p class="lede">Changing your password is on the <a href="<?= e(url('profile', 'security')) ?>">security page</a>.</p>

<?php if ($errors !== []): ?>
    <div class="flash error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('profile', 'update')) ?>" class="card">
    <?= $csrfField ?>
    <input type="hidden" name="baseUserId" value="<?= e($account->getBaseUserId()) ?>">

    <div class="row">
        <div>
            <label for="username">Username</label>
            <input class="<?= e($bad('username')) ?>" type="text" id="username" name="username"
                   maxlength="50" value="<?= $value('username', $account->getUsername()) ?>" required>
            <?= $err('username') ?>
        </div>
        <div>
            <label for="contactNumber">Contact number</label>
            <input class="<?= e($bad('contactNumber')) ?>" type="tel" id="contactNumber" name="contactNumber"
                   maxlength="20" value="<?= $value('contactNumber', $account->getContactNumber()) ?>" required>
            <?= $err('contactNumber') ?>
        </div>
    </div>

    <label for="email">Email</label>
    <input class="<?= e($bad('email')) ?>" type="email" id="email" name="email" maxlength="255"
           value="<?= $value('email', $account->getEmail()) ?>" required>
    <?= $err('email') ?>

    <?php if ($account instanceof User): ?>
        <h2 class="sub">Your profile</h2>

        <div class="row-3">
            <div>
                <label for="favoriteSport">Favourite sport</label>
                <input class="<?= e($bad('favoriteSport')) ?>" type="text" id="favoriteSport" name="favoriteSport"
                       maxlength="50" value="<?= $value('favoriteSport', $account->getFavoriteSport()) ?>">
                <?= $err('favoriteSport') ?>
            </div>
            <div>
                <label for="location">Where you play</label>
                <input class="<?= e($bad('location')) ?>" type="text" id="location" name="location"
                       maxlength="255" value="<?= $value('location', $account->getLocation()) ?>">
                <?= $err('location') ?>
            </div>
            <div>
                <label for="birthDate">Date of birth</label>
                <input class="<?= e($bad('birthDate')) ?>" type="date" id="birthDate" name="birthDate"
                       value="<?= $value('birthDate', $account->getBirthDate()?->format('Y-m-d')) ?>">
                <?= $err('birthDate') ?>
            </div>
        </div>

        <label for="profilePicURL">Profile picture address</label>
        <input class="<?= e($bad('profilePicURL')) ?>" type="text" id="profilePicURL" name="profilePicURL"
               maxlength="500" placeholder="/uploads/profile/me.jpg"
               value="<?= $value('profilePicURL', $account->getProfilePicURL()) ?>">
        <?= $err('profilePicURL') ?>

        <div class="row">
            <div>
                <label for="latitude">Latitude</label>
                <input class="<?= e($bad('latitude')) ?>" type="number" step="0.0000001" min="-90" max="90"
                       id="latitude" name="latitude" placeholder="3.2168000"
                       value="<?= $value('latitude', $account->getLatitude() !== null ? (string) $account->getLatitude() : null) ?>">
                <?= $err('latitude') ?>
            </div>
            <div>
                <label for="longitude">Longitude</label>
                <input class="<?= e($bad('longitude')) ?>" type="number" step="0.0000001" min="-180" max="180"
                       id="longitude" name="longitude" placeholder="101.7291000"
                       value="<?= $value('longitude', $account->getLongitude() !== null ? (string) $account->getLongitude() : null) ?>">
                <?= $err('longitude') ?>
            </div>
        </div>
        <p class="small muted">
            Coordinates are used only to work out how far away an event is. The distance
            is calculated on the server and only the rounded result is sent to the browser.
        </p>
    <?php endif; ?>

    <?php if ($account instanceof FacilityOwner): ?>
        <h2 class="sub">Payout details</h2>

        <div class="row">
            <div>
                <label for="bankName">Bank</label>
                <input class="<?= e($bad('bankName')) ?>" type="text" id="bankName" name="bankName"
                       maxlength="100" value="<?= $value('bankName', $account->getBankName()) ?>" required>
                <?= $err('bankName') ?>
            </div>
            <div>
                <label for="businessRegNum">Business registration no.</label>
                <input class="<?= e($bad('businessRegNum')) ?>" type="text" id="businessRegNum" name="businessRegNum"
                       maxlength="50" value="<?= $value('businessRegNum', $account->getBusinessRegNum()) ?>" required>
                <?= $err('businessRegNum') ?>
            </div>
        </div>

        <label for="bankAccountNum">Bank account number</label>
        <input class="<?= e($bad('bankAccountNum')) ?>" type="text" id="bankAccountNum" name="bankAccountNum"
               maxlength="50" autocomplete="off"
               placeholder="Currently <?= e($account->getMaskedBankAccountNum()) ?> - leave blank to keep it">
        <?= $err('bankAccountNum') ?>
        <p class="small muted">
            The stored number is never sent to this page, so it cannot be read out of the
            HTML. Leave the field empty to keep the one on file.
        </p>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn" type="submit">Save changes</button>
        <a class="btn ghost" href="<?= e(url('profile')) ?>">Cancel</a>
    </div>
</form>
