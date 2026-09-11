<?php
// Venue search for organisers. Author: Goh Jian Yu
// Receives: $facilities, $ratings, $criteria, $cities, $sports, $input

// Only a player can organise a game, so only a player is offered the second
// button. A venue owner may still browse and open a venue, which is useful for
// seeing what other venues in their area charge.
$canHostHere = App\Security\Auth::user()?->isPlayer() ?? false;
?>
<h1>Find a venue</h1>
<p class="lede">Filter by sport, city and price, then sort by name, price or how new the listing is.</p>

<form method="get" action="index.php" class="card toolbar">
    <input type="hidden" name="c" value="facility">
    <input type="hidden" name="a" value="search">

    <label class="inline-label" for="q">Name or street</label>
    <input class="medium-field" type="text" id="q" name="q" maxlength="100"
           value="<?= e((string) ($input['q'] ?? '')) ?>">

    <label class="inline-label" for="type">Sport</label>
    <select class="medium-field" id="type" name="type">
        <option value="">Any sport</option>
        <?php foreach ($sports as $sport): ?>
            <option value="<?= e($sport) ?>" <?= ($input['type'] ?? '') === $sport ? 'selected' : '' ?>>
                <?= e($sport) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="inline-label" for="city">City</label>
    <select class="medium-field" id="city" name="city">
        <option value="">Anywhere</option>
        <?php foreach ($cities as $city): ?>
            <option value="<?= e($city) ?>" <?= ($input['city'] ?? '') === $city ? 'selected' : '' ?>>
                <?= e($city) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="inline-label" for="max_fee">Up to (RM/h)</label>
    <input class="medium-field" type="number" id="max_fee" name="max_fee" min="0" step="1"
           value="<?= e((string) ($input['max_fee'] ?? '')) ?>">

    <label class="inline-label" for="sort">Sort by</label>
    <select class="medium-field" id="sort" name="sort">
        <option value="name"   <?= $criteria->sortBy === 'name' ? 'selected' : '' ?>>Name</option>
        <option value="price"  <?= $criteria->sortBy === 'price' ? 'selected' : '' ?>>Price</option>
        <option value="city"   <?= $criteria->sortBy === 'city' ? 'selected' : '' ?>>City</option>
        <option value="newest" <?= $criteria->sortBy === 'newest' ? 'selected' : '' ?>>Newest</option>
    </select>

    <button class="btn small" type="submit">Filter</button>
    <a class="btn ghost small" href="<?= e(url('facility', 'search')) ?>">Clear</a>
</form>

<?php if ($facilities === []): ?>
    <div class="card empty">
        <p>No venues match those filters.</p>
        <p class="small">Only venues that have been approved appear here.</p>
    </div>
<?php else: ?>
    <p class="small muted"><?= e((string) count($facilities)) ?> venue(s) found.</p>

    <div class="grid">
        <?php foreach ($facilities as $facility): ?>
            <?php $rating = $ratings[(string) $facility->getFacilityId()] ?? null; ?>
            <div class="card">
                <?php if ($facility->getImageUrl() !== null): ?>
                    <img class="upload-preview" src="<?= e(imageSrc($facility->getImageUrl())) ?>"
                         alt="<?= e($facility->getName()) ?>">
                <?php endif; ?>

                <div class="item-head">
                    <div>
                        <strong><?= e($facility->getName()) ?></strong>
                        <div class="small muted"><?= e($facility->getType()) ?></div>
                    </div>
                    <div class="rating-block"><?= stars($rating) ?></div>
                </div>

                <div class="stat">
                    <div><span>Hourly fee</span><strong><?= e(money($facility->getBookingFee())) ?></strong></div>
                    <div>
                        <span>Open</span>
                        <strong>
                            <?= e(hhmm($facility->getOperationalHrsStart())) ?>&ndash;<?= e(hhmm($facility->getOperationalHrsEnd())) ?><?= $facility->closesAfterMidnight() ? ' (next day)' : '' ?>
                        </strong>
                    </div>
                    <div><span>City</span><strong><?= e($facility->getCity()) ?></strong></div>
                </div>

                <p class="small muted gap-below"><?= e($facility->getFullAddress()) ?></p>

                <?php
                // Two ways on from here. Opening the venue shows its free slots
                // and the games already booked there; creating an event skips
                // straight to the form with this venue already chosen.
                ?>
                <a class="btn ghost small" href="<?= e(url('facility', 'show', ['id' => $facility->getFacilityId()])) ?>">
                    View venue
                </a>
                <?php if ($canHostHere): ?>
                    <a class="btn small" href="<?= e(url('event', 'create', ['facilityId' => $facility->getFacilityId()])) ?>">
                        Hold an event here
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

