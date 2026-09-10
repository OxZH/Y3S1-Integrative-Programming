<?php
// A facility owner's own venues. Author: Goh Jian Yu
// Receives: $facilities, $deletable
?>
<h1>My venues</h1>
<p class="lede">Register venues, adjust pricing and opening hours, and delist a venue without losing its history.</p>

<div class="toolbar">
    <a class="btn" href="<?= e(url('facility', 'create')) ?>">Register a venue</a>
</div>

<?php if ($facilities === []): ?>
    <div class="card empty">
        <p>You have not registered a venue yet.</p>
        <a class="btn" href="<?= e(url('facility', 'create')) ?>">Register your first venue</a>
    </div>
<?php else: ?>
    <div class="card card-table">
        <table>
            <thead>
            <tr><th>Venue</th><th>Type</th><th>Fee</th><th>Hours</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($facilities as $facility): ?>
                <?php
                $status = $facility->getStatus();
                $statusClass = 'dead';

                if ($status->value === 'ACTIVE') {
                    $statusClass = 'live';
                } else if ($status->value === 'PENDING') {
                    $statusClass = 'wait';
                }
                ?>
                <tr>
                    <td>
                        <strong><?= e($facility->getName()) ?></strong>
                        <div class="small muted"><?= e($facility->getFullAddress()) ?></div>
                    </td>
                    <td><?= e($facility->getType()) ?></td>
                    <td><?= e(money($facility->getBookingFee())) ?></td>
                    <td class="small"><?= e(hhmm($facility->getOperationalHrsStart())) ?>&ndash;<?= e(hhmm($facility->getOperationalHrsEnd())) ?></td>
                    <td><span class="pill <?= e($statusClass) ?>"><?= e($status->label()) ?></span></td>
                    <td class="actions">
                        <a class="btn ghost small" href="<?= e(url('facility', 'edit', ['id' => $facility->getFacilityId()])) ?>">Edit</a>

                        <?php if ($status->isBookable()): ?>
                            <form method="post" action="<?= e(url('facility', 'suspend')) ?>" class="inline-form">
                                <?= $csrfField ?>
                                <input type="hidden" name="facilityId" value="<?= e((string) $facility->getFacilityId()) ?>">
                                <button class="btn ghost small" type="submit">Delist</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('facility', 'reactivate')) ?>" class="inline-form">
                                <?= $csrfField ?>
                                <input type="hidden" name="facilityId" value="<?= e((string) $facility->getFacilityId()) ?>">
                                <button class="btn ghost small" type="submit">List</button>
                            </form>
                        <?php endif; ?>

                        <?php
                        // Only offered when it would really delete. A venue with
                        // games or reviews behind it can only be delisted, and
                        // the Delist button above already does that - so there is
                        // no button here that has to apologise afterwards.
                        ?>
                        <?php if ($deletable[(string) $facility->getFacilityId()] ?? false): ?>
                            <form method="post" action="<?= e(url('facility', 'delete')) ?>" class="inline-form"
                                  data-confirm="Delete this venue? Nothing is booked or reviewed against it, so the record goes for good.">
                                <?= $csrfField ?>
                                <input type="hidden" name="facilityId" value="<?= e((string) $facility->getFacilityId()) ?>">
                                <button class="btn danger small" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="small muted">
        A new venue stays <strong>Pending</strong> until approved. Delisting hides it from search and
        stops new bookings; games already scheduled there are untouched. Once a venue has games or
        reviews against it, it can only be delisted, so <strong>Delete</strong> is no longer offered.
    </p>
<?php endif; ?>
