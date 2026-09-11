<?php
// Event creation form. Author: Goh Jian Yu

use App\Competitiveness;
use App\EventVisibility;
use App\FitnessRequirement;
use App\Model\Facility;
use App\SkillLevel;
// Receives: $input, $errors, $venues, $ratings, $maxDate, $busy

$bad = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? 'bad' : '';
};

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<div class="field-error">' . e($errors[$field]) . '</div>' : '';
};

$options = static function (array $cases, ?string $selected): string {
    $html = '';

    foreach ($cases as $case) {
        $html .= sprintf(
            '<option value="%s" %s>%s</option>',
            e($case->value),
            $selected === $case->value ? 'selected' : '',
            e($case->label())
        );
    }

    return $html;
};

$selectedVenue = (string) ($input['facilityId'] ?? '');

// Every half hour of the day. Bookings are taken on the hour or the half hour
// and nothing finer, which keeps a court from being left with a useless seven
// minute gap between two games. app.js disables the ones that are outside the
// venue's hours, already booked, or in the past.
$halfHours = static function (string $selected): string {
    $html = '';

    for ($minutes = 0; $minutes < 24 * 60; $minutes += 30) {
        $label = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        $html .= sprintf(
            '<option value="%s" %s>%s</option>',
            e($label),
            $selected === $label ? 'selected' : '',
            e($label)
        );
    }

    return $html;
};

// Venue details for the preview panel. Handed to public/js/app.js through a
// data attribute rather than an inline script, so no PHP ends up in the
// JavaScript file and no JavaScript ends up in this view.
$venueData = [];

foreach ($venues as $venue) {
    $id     = (string) $venue->getFacilityId();
    $rating = $ratings[$id] ?? null;

    $venueData[$id] = [
        'name'    => $venue->getName() . ' · ' . $venue->getType(),
        'address' => $venue->getFullAddress(),
        'fee'     => money($venue->getBookingFee()),
        'hours'   => hhmm($venue->getOperationalHrsStart()) . '–' . hhmm($venue->getOperationalHrsEnd())
                     . ($venue->closesAfterMidnight() ? ' (next day)' : ''),
        'image'   => imageSrc($venue->getImageUrl()),
        'rating'  => starsText($rating),
        'sport'   => $venue->getType(),

        // the raw times, for the slot dropdowns. 'hours' above is the wording
        // shown to the reader and is no use for arithmetic.
        'opens'   => $venue->getOperationalHrsStart(),
        'closes'  => $venue->getOperationalHrsEnd(),
        'url'     => url('facility', 'show', ['id' => $id]),
    ];
}
?>
<h1>Create an event</h1>
<p class="lede">Set up the game, choose where it will be played, then pay for the venue to publish it.</p>

<?php if ($errors !== []): ?>
    <div class="flash error">Please correct the highlighted fields.</div>
<?php endif; ?>

<?php if ($venues === []): ?>
    <div class="card empty">
        <p>There are no bookable venues yet.</p>
        <p class="small">A venue has to be registered and approved before an event can be held at it.</p>
    </div>
<?php else: ?>

<form method="post" action="<?= e(url('event', 'store')) ?>" class="card">
    <?= $csrfField ?>

    <label for="name">Event name</label>
    <input class="<?= e($bad('name')) ?>" type="text" id="name" name="name" maxlength="150"
           placeholder="Friday Night Doubles" value="<?= old($input, 'name') ?>" required>
    <?= $err('name') ?>

    <label for="facilityId">Venue</label>
    <select class="<?= e($bad('facilityId')) ?>" id="facilityId" name="facilityId" required
            data-venues="<?= e(json_encode($venueData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
        <option value="">Choose a venue&hellip;</option>
        <?php foreach ($venues as $venue): ?>
            <?php
            $id     = (string) $venue->getFacilityId();
            $rating = $ratings[$id] ?? null;
            ?>
            <option value="<?= e($id) ?>" <?= $selectedVenue === $id ? 'selected' : '' ?>>
                <?= e($venue->getName()) ?>
                &mdash; <?= e($venue->getCity()) ?>
                &mdash; <?= e(money($venue->getBookingFee())) ?>/h
                &mdash; <?= e(starsText($rating)) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?= $err('facilityId') ?>

    <?php
    // Not an input. The venue decides the sport, so this only reports what was
    // decided. app.js fills it in from the same data the preview panel uses,
    // and nothing is submitted, because the server reads the sport off the
    // facility rather than off the request.
    ?>
    <label for="sportShown">Sport</label>
    <input class="readonly-field" type="text" id="sportShown" value="" readonly
           placeholder="Choose a venue first">
    <p class="small muted">Set by the venue. A badminton hall only hosts badminton.</p>

    <div class="card card-inset venue-preview" id="venuePreview" hidden>
        <div class="venue-preview-inner">
            <img class="venue-preview-image" data-field="image" alt="" hidden>
            <div class="venue-preview-body">
                <strong data-field="name"></strong>
                <div class="small muted" data-field="address"></div>
                <div class="stat stat-tight">
                    <div><span>Hourly fee</span><strong data-field="fee"></strong></div>
                    <div><span>Open</span><strong data-field="hours"></strong></div>
                </div>
            </div>
            <div class="venue-preview-rating">
                <span class="small muted">Rating</span>
                <strong data-field="rating"></strong>
            </div>
            <a class="btn ghost small" data-field="link" href="#" target="_blank" rel="noopener">
                View venue details
            </a>
        </div>
    </div>
    <p class="form-note">
        Ratings come from the Social Networking &amp; Review module. Venue details open in a new tab so
        you do not lose this form.
    </p>

    <div class="row-3">
        <div>
            <label for="eventDate">Date</label>
            <input class="<?= e($bad('eventDate')) ?>" type="date" id="eventDate" name="eventDate"
                   min="<?= e(date('Y-m-d')) ?>" max="<?= e($maxDate) ?>"
                   value="<?= old($input, 'eventDate') ?>" required>
            <p class="small muted">Up to <?= e(date('d M Y', strtotime($maxDate))) ?>.</p>
            <?= $err('eventDate') ?>
        </div>
        <div>
            <label for="startTime">Start time</label>
            <select class="<?= e($bad('startTime')) ?>" id="startTime" name="startTime" required
                    data-busy="<?= e(json_encode($busy, JSON_UNESCAPED_UNICODE)) ?>">
                <option value="">Choose a time&hellip;</option>
                <?= $halfHours(old($input, 'startTime')) ?>
            </select>
            <?= $err('startTime') ?>
        </div>
        <div>
            <label for="endTime">End time</label>
            <select class="<?= e($bad('endTime')) ?>" id="endTime" name="endTime" required>
                <option value="">Choose a time&hellip;</option>
                <?= $halfHours(old($input, 'endTime')) ?>
            </select>
            <?= $err('endTime') ?>
        </div>
    </div>
    <p class="form-note">
        Times outside the venue's opening hours, already booked, or in the past are greyed out once a
        venue and a date are chosen.
    </p>

    <div class="row">
        <div>
            <label for="minParticipants">Minimum players</label>
            <input class="<?= e($bad('minParticipants')) ?>" type="number" min="1" max="200"
                   id="minParticipants" name="minParticipants" value="<?= old($input, 'minParticipants', '4') ?>" required>
            <?= $err('minParticipants') ?>
        </div>
        <div>
            <label for="maxParticipants">Maximum players</label>
            <input class="<?= e($bad('maxParticipants')) ?>" type="number" min="1" max="200"
                   id="maxParticipants" name="maxParticipants" value="<?= old($input, 'maxParticipants', '8') ?>" required>
            <?= $err('maxParticipants') ?>
        </div>
    </div>

    <div class="row-3">
        <div>
            <label for="skillLevel">Skill level</label>
            <select class="<?= e($bad('skillLevel')) ?>" id="skillLevel" name="skillLevel" required>
                <?= $options(SkillLevel::cases(), $input['skillLevel'] ?? null) ?>
            </select>
            <?= $err('skillLevel') ?>
        </div>
        <div>
            <label for="fitnessRequirement">Fitness requirement</label>
            <select class="<?= e($bad('fitnessRequirement')) ?>" id="fitnessRequirement" name="fitnessRequirement" required>
                <?= $options(FitnessRequirement::cases(), $input['fitnessRequirement'] ?? null) ?>
            </select>
            <?= $err('fitnessRequirement') ?>
        </div>
        <div>
            <label for="competitiveness">Event nature</label>
            <select class="<?= e($bad('competitiveness')) ?>" id="competitiveness" name="competitiveness" required>
                <?= $options(Competitiveness::cases(), $input['competitiveness'] ?? null) ?>
            </select>
            <?= $err('competitiveness') ?>
        </div>
    </div>

    <div class="row">
        <div>
            <label for="visibility">Who can see this event</label>
            <select class="<?= e($bad('visibility')) ?>" id="visibility" name="visibility" required>
                <?= $options(EventVisibility::cases(), $input['visibility'] ?? null) ?>
            </select>
            <?= $err('visibility') ?>
        </div>
        <div>
            <label for="feePerParticipant">Fee per player (RM)</label>
            <input class="<?= e($bad('feePerParticipant')) ?>" type="number" step="0.01" min="0"
                   id="feePerParticipant" name="feePerParticipant" value="<?= old($input, 'feePerParticipant', '0.00') ?>">
            <?= $err('feePerParticipant') ?>
        </div>
    </div>
    <p class="form-note">
        Friends-only games are hidden from the public listing, but anyone you send an invite link to
        can still open them.
    </p>

    <div class="form-actions">
        <button class="btn" type="submit">Continue to venue booking</button>
        <a class="btn ghost" href="<?= e(url('event', 'mine')) ?>">Cancel</a>
    </div>
</form>

<?php endif; ?>
