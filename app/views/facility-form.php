<?php
// Venue registration and edit form. Author: Goh Jian Yu
// Receives: $facility, $input, $errors, and $sports

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

<form method="post" action="<?= e(url('facility', $isEdit ? 'update' : 'store')) ?>" class="card"
      enctype="multipart/form-data">
    <?= $csrfField ?>

    <?php if ($isEdit): ?>
        <input type="hidden" name="facilityId" value="<?= e((string) $facility->getFacilityId()) ?>">
    <?php endif; ?>

    <label for="name">Venue name</label>
    <input class="<?= e($bad('name')) ?>" type="text" id="name" name="name" maxlength="150"
           value="<?= $value('name', $facility?->getName()) ?>" required>
    <?= $err('name') ?>

    <label for="type">Sport played here</label>
    <?php $chosenSport = $value('type', $facility?->getType()); ?>
    <select class="<?= e($bad('type')) ?>" id="type" name="type" required>
        <option value="">Choose a sport&hellip;</option>
        <?php foreach ($sports as $sportName): ?>
            <option value="<?= e($sportName) ?>" <?= $chosenSport === $sportName ? 'selected' : '' ?>>
                <?= e($sportName) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="small muted">
        Every game booked here plays this sport. A venue used for more than one sport
        should be registered once per sport.
    </p>
    <?= $err('type') ?>

    <label for="addressLine">Street address</label>
    <input class="<?= e($bad('addressLine')) ?>" type="text" id="addressLine" name="addressLine" maxlength="255"
           placeholder="1-2-2, Taman Setiawangsa, Jalan Genting Klang"
           value="<?= $value('addressLine', $facility?->getAddressLine()) ?>" required>
    <p class="small muted">Unit, area and street, separated by commas. The street can be left out.</p>
    <?= $err('addressLine') ?>

    <div class="row-3">
        <div>
            <label for="postcode">Postcode</label>
            <input class="<?= e($bad('postcode')) ?>" type="text" id="postcode" name="postcode"
                   inputmode="numeric" maxlength="5" pattern="\d{5}" placeholder="53300"
                   value="<?= $value('postcode', $facility?->getPostcode()) ?>" required
                   data-postcode-source="<?= e(asset('data/postcodes.json')) ?>">
            <?= $err('postcode') ?>
        </div>
        <?php
        // Neither of these is an input. Pos Malaysia gives a postcode one post
        // town in one state, so the field beside them decides both, and they
        // only report what it decided. Nothing here is submitted, and the
        // server looks both up again from the postcode alone.
        ?>
        <div>
            <label for="cityShown">City</label>
            <input class="readonly-field" type="text" id="cityShown" readonly
                   value="<?= e($value('city', $facility?->getCity())) ?>"
                   placeholder="From the postcode">
        </div>
        <div>
            <label for="stateShown">State</label>
            <input class="readonly-field" type="text" id="stateShown" readonly
                   value="<?= e($value('state', $facility?->getState())) ?>"
                   placeholder="From the postcode">
        </div>
    </div>
    <p class="small muted">
        The town and the state come from the postcode, so there is nothing to choose and no way to
        pair a town with the wrong state.
    </p>

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
            <p class="small muted">Closing earlier than opening means the next day, eg 22:00 to 02:00.</p>
        </div>
    </div>

    <label for="image">Photo of the venue (optional)</label>
    <?php if ($isEdit && $facility->getImageUrl() !== null): ?>
        <img class="upload-preview" src="<?= e(imageSrc($facility->getImageUrl())) ?>"
             alt="<?= e($facility->getName()) ?>">
    <?php endif; ?>
    <input class="<?= e($bad('image')) ?>" type="file" id="image" name="image"
           accept=".jpg,.jpeg,.png,.webp">
    <?= $err('image') ?>
    <p class="small muted">
        JPG, JPEG, PNG or WEBP, up to 3 MB.
        <?= $isEdit ? 'Leave this empty to keep the photo you already have.' : '' ?>
    </p>

    <details class="advanced">
        <summary>Exact map position (optional)</summary>
        <p class="small muted">
            The pin is worked out from the address above, which is close enough for
            sorting venues by distance. Fill these in only if you want it exact.
        </p>
        <div class="row">
            <div>
                <label for="latitude">Latitude</label>
                <input class="<?= e($bad('latitude')) ?>" type="number" step="0.0000001" min="-90" max="90"
                       id="latitude" name="latitude" placeholder="3.2145000"
                       value="<?= $value('latitude', $facility !== null ? (string) $facility->getLatitude() : null) ?>">
                <?= $err('latitude') ?>
            </div>
            <div>
                <label for="longitude">Longitude</label>
                <input class="<?= e($bad('longitude')) ?>" type="number" step="0.0000001" min="-180" max="180"
                       id="longitude" name="longitude" placeholder="101.7268000"
                       value="<?= $value('longitude', $facility !== null ? (string) $facility->getLongitude() : null) ?>">
                <?= $err('longitude') ?>
            </div>
        </div>
    </details>

    <div class="form-actions">
        <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Submit venue' ?></button>
        <a class="btn ghost" href="<?= e(url('facility', 'mine')) ?>">Cancel</a>
    </div>
</form>
