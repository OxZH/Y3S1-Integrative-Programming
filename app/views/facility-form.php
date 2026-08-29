<?php
// Venue registration and edit form. Author: Goh Jian Yu

/** @var \App\Model\Facility|null $facility */
/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */

$isEdit = $facility !== null;

$value = static function (string $field, ?string $stored) use ($input): string {
    return old($input, $field, $stored ?? '');
};

$bad = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? 'bad' : '';
};

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<div class="field-error">' . e($errors[$field]) . '</div>' : '';
};
?>
<h1><?= $isEdit ? 'Edit ' . e($facility->getName()) : 'Register a venue' ?></h1>
<p class="lede">
    <?= $isEdit
        ? 'Bookings already made keep the price they were made at.'
        : 'Your venue is reviewed before it appears in search results.' ?>
</p>

<?php if ($errors !== []): ?>
    <div class="flash error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('facility', $isEdit ? 'update' : 'store')) ?>" class="card">
    <?= $csrfField ?>

    <?php if ($isEdit): ?>
        <input type="hidden" name="facilityId" value="<?= e((string) $facility->getFacilityId()) ?>">
    <?php endif; ?>

    <label for="name">Venue name</label>
    <input class="<?= e($bad('name')) ?>" type="text" id="name" name="name" maxlength="150"
           value="<?= $value('name', $facility?->getName()) ?>" required>
    <?= $err('name') ?>

    <label for="type">Venue type</label>
    <input class="<?= e($bad('type')) ?>" type="text" id="type" name="type" maxlength="50"
           placeholder="Badminton Hall, Futsal Court" value="<?= $value('type', $facility?->getType()) ?>" required>
    <?= $err('type') ?>

    <label for="addressLine">Street address</label>
    <input class="<?= e($bad('addressLine')) ?>" type="text" id="addressLine" name="addressLine" maxlength="255"
           value="<?= $value('addressLine', $facility?->getAddressLine()) ?>" required>
    <?= $err('addressLine') ?>

    <div class="row">
        <div>
            <label for="city">City</label>
            <input class="<?= e($bad('city')) ?>" type="text" id="city" name="city" maxlength="100"
                   value="<?= $value('city', $facility?->getCity()) ?>" required>
            <?= $err('city') ?>
        </div>
        <div>
            <label for="state">State</label>
            <input class="<?= e($bad('state')) ?>" type="text" id="state" name="state" maxlength="100"
                   value="<?= $value('state', $facility?->getState()) ?>" required>
            <?= $err('state') ?>
        </div>
    </div>

    <div class="row-3">
        <div>
            <label for="bookingFee">Hourly fee (RM)</label>
            <input class="<?= e($bad('bookingFee')) ?>" type="number" step="0.01" min="0"
                   id="bookingFee" name="bookingFee"
                   value="<?= $value('bookingFee', $facility !== null ? number_format($facility->getBookingFee(), 2, '.', '') : null) ?>" required>
            <?= $err('bookingFee') ?>
        </div>
        <div>
            <label for="operationalHrsStart">Opens at</label>
            <input class="<?= e($bad('operationalHrsStart')) ?>" type="time" id="operationalHrsStart" name="operationalHrsStart"
                   value="<?= $value('operationalHrsStart', $facility !== null ? hhmm($facility->getOperationalHrsStart()) : '08:00') ?>" required>
            <?= $err('operationalHrsStart') ?>
        </div>
        <div>
            <label for="operationalHrsEnd">Closes at</label>
            <input class="<?= e($bad('operationalHrsEnd')) ?>" type="time" id="operationalHrsEnd" name="operationalHrsEnd"
                   value="<?= $value('operationalHrsEnd', $facility !== null ? hhmm($facility->getOperationalHrsEnd()) : '22:00') ?>" required>
            <?= $err('operationalHrsEnd') ?>
        </div>
    </div>

    <div class="row">
        <div>
            <label for="latitude">Latitude</label>
            <input class="<?= e($bad('latitude')) ?>" type="number" step="0.0000001" min="-90" max="90"
                   id="latitude" name="latitude" placeholder="3.2145000"
                   value="<?= $value('latitude', $facility !== null ? (string) $facility->getLatitude() : null) ?>" required>
            <?= $err('latitude') ?>
        </div>
        <div>
            <label for="longitude">Longitude</label>
            <input class="<?= e($bad('longitude')) ?>" type="number" step="0.0000001" min="-180" max="180"
                   id="longitude" name="longitude" placeholder="101.7268000"
                   value="<?= $value('longitude', $facility !== null ? (string) $facility->getLongitude() : null) ?>" required>
            <?= $err('longitude') ?>
        </div>
    </div>
    <p class="small muted">Coordinates drive the map pins and distance sorting.</p>

    <label for="imageUrl">Image address (optional)</label>
    <input class="<?= e($bad('imageUrl')) ?>" type="text" id="imageUrl" name="imageUrl" maxlength="500"
           placeholder="/uploads/facility/my-venue.jpg" value="<?= $value('imageUrl', $facility?->getImageUrl()) ?>">
    <?= $err('imageUrl') ?>

    <div class="form-actions">
        <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Submit venue' ?></button>
        <a class="btn ghost" href="<?= e(url('facility', 'mine')) ?>">Cancel</a>
    </div>
</form>
