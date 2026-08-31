<?php
// Event creation form. Author: Goh Jian Yu

use App\Competitiveness;
use App\EventVisibility;
use App\FitnessRequirement;
use App\Model\Facility;
use App\SkillLevel;

/** @var array<string,mixed> $input */
/** @var array<string,string> $errors */
/** @var Facility[] $venues */
/** @var array<string,float> $ratings */

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

/*
 * Venue details for the preview panel. Handed to public/js/app.js through a
 * data attribute rather than an inline script, so no PHP ends up in the
 * JavaScript file and no JavaScript ends up in this view.
 */
$venueData = [];

foreach ($venues as $venue) {
    $id     = (string) $venue->getFacilityId();
    $rating = $ratings[$id] ?? null;

    $venueData[$id] = [
        'name'    => $venue->getName() . ' · ' . $venue->getType(),
        'address' => $venue->getFullAddress(),
        'fee'     => money($venue->getBookingFee()),
        'hours'   => hhmm($venue->getOperationalHrsStart()) . '–' . hhmm($venue->getOperationalHrsEnd()),
        'image'   => $venue->getImageUrl(),
        'rating'  => $rating !== null ? number_format($rating, 1) . ' / 5' : 'Not rated yet',
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

    <label for="sport">Sport</label>
    <input class="<?= e($bad('sport')) ?>" type="text" id="sport" name="sport" maxlength="50"
           placeholder="Badminton" value="<?= old($input, 'sport') ?>" required>
    <?= $err('sport') ?>

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
                &mdash; <?= $rating !== null ? e(number_format($rating, 1)) . '/5' : 'unrated' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?= $err('facilityId') ?>

    <div class="card card-inset venue-preview" id="venuePreview" hidden>
        <div class="venue-preview-inner">
            <img class="venue-preview-image" data-field="image" alt="" hidden>
            <div class="venue-preview-body">
                <strong data-field="name"></strong>
                <div class="small muted" data-field="address"></div>
                <div class="stat stat-tight">
                    <div><span>Rating</span><strong data-field="rating"></strong></div>
                    <div><span>Hourly fee</span><strong data-field="fee"></strong></div>
                    <div><span>Open</span><strong data-field="hours"></strong></div>
                </div>
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
                   min="<?= e(date('Y-m-d')) ?>" value="<?= old($input, 'eventDate') ?>" required>
            <?= $err('eventDate') ?>
        </div>
        <div>
            <label for="startTime">Start time</label>
            <input class="<?= e($bad('startTime')) ?>" type="time" id="startTime" name="startTime"
                   value="<?= old($input, 'startTime') ?>" required>
            <?= $err('startTime') ?>
        </div>
        <div>
            <label for="endTime">End time</label>
            <input class="<?= e($bad('endTime')) ?>" type="time" id="endTime" name="endTime"
                   value="<?= old($input, 'endTime') ?>" required>
            <?= $err('endTime') ?>
        </div>
    </div>
    <p class="form-note">
        The slot has to sit inside the venue's opening hours and not overlap a game already booked there.
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
