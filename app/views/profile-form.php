<?php
// Profile edit form. Author: Ivan Lim Tze Yang

/** @var \App\Model\Account $account */
/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */

use App\Bank;
use App\Domain\ProfileImage;
use App\Model\FacilityOwner;
use App\Model\User;
use App\Sport;

$bad = static fn (string $f): string => isset($errors[$f]) ? 'bad' : '';
$err = static fn (string $f): string => isset($errors[$f])
    ? '<div class="field-error">' . e($errors[$f]) . '</div>'
    : '';

$value = static function (string $field, ?string $stored) use ($input): string {
    return old($input, $field, $stored ?? '');
};

// What was submitted after a failed save, otherwise what is stored.
$chosenSports = is_array($input['favoriteSports'] ?? null)
    ? $input['favoriteSports']
    : ($account instanceof User ? $account->getFavoriteSports() : []);

$latestBirthDate = (new DateTimeImmutable('today'))->modify('-3 years')->format('Y-m-d');
?>
<h1>Edit profile</h1>
<p class="lede">Changing your password is on the <a href="<?= e(url('profile', 'security')) ?>">security page</a>.</p>

<?php if ($errors !== []): ?>
    <div class="flash error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('profile', 'update')) ?>" class="card"
      enctype="multipart/form-data">
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
                <label for="location">Where you play</label>
                <input class="<?= e($bad('location')) ?>" type="text" id="location" name="location"
                       maxlength="255" value="<?= $value('location', $account->getLocation()) ?>"
                       data-location-lookup="<?= e(url('location', 'lookup')) ?>">
                <div class="small" id="locationConfirm" hidden></div>
                <?= $err('location') ?>
                <p class="small muted">
                    Your map position comes from this address. Others only see how far away
                    you are, never where you are.
                </p>
            </div>
            <div>
                <label for="birthDate">Date of birth</label>
                <input class="<?= e($bad('birthDate')) ?>" type="date" id="birthDate" name="birthDate"
                       max="<?= e($latestBirthDate) ?>"
                       value="<?= $value('birthDate', $account->getBirthDate()?->format('Y-m-d')) ?>">
                <?= $err('birthDate') ?>
                <p class="small muted">You must be at least 3 years old.</p>
            </div>
        </div>

        <?php $currentPicture = ProfileImage::cacheBustedSrc($account->getProfilePicURL()); ?>

        <label for="profilePicture">Profile picture</label>
        <div class="dropzone" data-dropzone="profilePicture">
            <img class="upload-preview<?= $currentPicture === '' ? ' is-hidden' : '' ?>"
                 id="profilePicturePreview" alt=""
                 src="<?= e($currentPicture) ?>">
            <p class="dropzone-hint" id="profilePictureHint">
                Drag a picture here, or <span class="dropzone-link">choose a file</span>.
            </p>
            <input class="<?= e($bad('profilePicture')) ?>" type="file" id="profilePicture"
                   name="profilePicture" accept=".jpg,.jpeg,.png">
        </div>
        <?= $err('profilePicture') ?>
        <p class="small muted">
            JPG or PNG, up to 2 MB. Leave it alone to keep the picture you already have.
            <?php if ($account->getProfilePicURL() !== null): ?>
                <label class="inline-label">
                    <input type="checkbox" name="removeProfilePicture" value="1"> Remove my picture
                </label>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($account instanceof FacilityOwner): ?>
        <h2 class="sub">Payout details</h2>

        <?php $chosenBank = $value('bankName', $account->getBankName()); ?>

        <label for="bankName">Bank</label>
        <select class="<?= e($bad('bankName')) ?>" id="bankName" name="bankName" required>
            <option value="">Choose your bank</option>
            <?php foreach (Bank::cases() as $bank): ?>
                <option value="<?= e($bank->value) ?>" <?= $chosenBank === $bank->value ? 'selected' : '' ?>>
                    <?= e($bank->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?= $err('bankName') ?>

        <label for="bankAccountNum">Bank account number</label>
        <input class="<?= e($bad('bankAccountNum')) ?>" type="text" id="bankAccountNum" name="bankAccountNum"
               maxlength="50" autocomplete="off"
               placeholder="Currently <?= e($account->getMaskedBankAccountNum()) ?> - leave blank to keep it">
        <?= $err('bankAccountNum') ?>
        <p class="small muted">Leave it empty to keep the number on file.</p>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn" type="submit">Save changes</button>
        <a class="btn ghost" href="<?= e(url('profile')) ?>">Cancel</a>
    </div>
</form>
