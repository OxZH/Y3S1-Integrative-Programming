<?php
// Venue detail. Author: Goh Jian Yu

use App\Security\Auth;

/** @var \App\Model\Facility $facility */
/** @var \App\Model\Event[] $events */
/** @var array $slots */
/** @var \DateTimeImmutable $slotDate */
/** @var \App\Model\Review[] $reviews */
/** @var array<string,\App\Model\Account|null> $reviewAuthors */
/** @var int $reviewPage */
/** @var int $reviewPages */
/** @var bool $isAdmin */

use App\Model\Admin;
use App\Model\User;

$owner = $facility->getOwner();

// Opening hours. A venue can close after midnight, so say which day it closes
// on rather than leaving "22:00-02:00" looking like a mistake.
$hours = hhmm($facility->getOperationalHrsStart()) . '–' . hhmm($facility->getOperationalHrsEnd());

if ($facility->closesAfterMidnight()) {
    $hours .= ' (next day)';
}
?>
<div class="page-head">
    <div>
        <h1><?= e($facility->getName()) ?></h1>
        <p class="lede-flush"><?= e($facility->getFullAddress()) ?></p>
    </div>
    <?php if (Auth::check() && !$isAdmin): ?>
        <form class="rating-widget" method="post" action="<?= e(url('rating', 'store')) ?>"
            data-rating-widget data-current-rating="<?= e((string) ($currentRating ?? 0)) ?>">
            <?= $csrfField ?? '' ?>
            <input type="hidden" name="targetType" value="facility">
            <input type="hidden" name="targetId" value="<?= e($facility->getFacilityId()) ?>">
            <span class="small muted">Your rating</span>
            <div class="rating-stars" role="group" aria-label="Rate this facility from one to five stars">
                <?php for ($star = 1; $star <= 5; $star++): ?>
                    <button class="rating-star <?= $star <= ($currentRating ?? 0) ? 'is-selected' : '' ?>"
                        type="submit" name="rating" value="<?= $star ?>" aria-label="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>">&#9733;</button>
                <?php endfor; ?>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($facility->getImageUrl() !== null): ?>
    <img class="venue-photo" src="<?= e(imageSrc($facility->getImageUrl())) ?>"
         alt="<?= e($facility->getName()) ?>">
<?php endif; ?>

<div class="row">
    <div class="card">
        <h2>Venue details</h2>
        <div class="stat">
            <div><span>Type</span><strong><?= e($facility->getType()) ?></strong></div>
            <div><span>Hourly fee</span><strong><?= e(money($facility->getBookingFee())) ?></strong></div>
            <div><span>Opening hours</span><strong><?= e($hours) ?></strong></div>
            <div><span>Status</span><strong><?= e($facility->getStatus()->label()) ?></strong></div>
        </div>
    </div>

    <div>
        <div class="card rating-block">
            <h2>Rating</h2>
            <?= stars($rating) ?>
            <p class="small muted sub-tight">From the Social Networking &amp; Review module.</p>
        </div>

        <div class="card">
            <h2>Operated by</h2>
            <?php if ($owner !== null): ?>
                <p class="flush"><strong><?= e($owner->getUsername()) ?></strong></p>
                <p class="small muted sub-tight">
                    <?= e($owner->getEmail()) ?><br>
                    <?= e($owner->getContactNumber()) ?>
                </p>
            <?php else: ?>
                <p class="muted">Owner details are unavailable.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<h2>Free slots on <?= e($slotDate->format('D, d M Y')) ?></h2>
<div class="card">
    <form method="get" action="index.php" class="toolbar">
        <input type="hidden" name="c" value="facility">
        <input type="hidden" name="a" value="show">
        <input type="hidden" name="id" value="<?= e((string) $facility->getFacilityId()) ?>">
        <label class="inline-label" for="date">Show a different day</label>
        <input class="narrow-field" type="date" id="date" name="date" value="<?= e($slotDate->format('Y-m-d')) ?>">
        <button class="btn ghost small" type="submit">Go</button>
    </form>

    <?php if ($slots === []): ?>
        <p class="muted small flush">No two-hour slot is free on this day.</p>
    <?php else: ?>
        <div class="chip-list">
            <?php foreach ($slots as $slot): ?>
                <span class="pill"><?= e(hhmm($slot['start'])) ?>&ndash;<?= e(hhmm($slot['end'])) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<h2>Games at this venue</h2>
<?php if ($events === []): ?>
    <div class="card empty">
        <p>No upcoming games here yet.</p>
    </div>
<?php else: ?>
    <div class="card card-table">
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Sport</th>
                    <th>When</th>
                    <th>Players</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><strong><?= e($event->getName()) ?></strong></td>
                        <td><?= e($event->getSport()) ?></td>
                        <td>
                            <?= e($event->getEventDate()->format('d M')) ?>,
                            <?= e(hhmm($event->getStartTime())) ?>&ndash;<?= e(hhmm($event->getEndTime())) ?>
                        </td>
                        <td class="muted"><?= e((string) $event->getMaxParticipants()) ?> max</td>
                        <td><a class="btn ghost small" href="<?= e(url('event', 'show', ['id' => $event->getEventId()])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (Auth::check()): ?>
    <a class="btn" href="<?= e(url('event', 'create', ['facilityId' => $facility->getFacilityId()])) ?>">
        Create an event here
    </a>

    <section class="reviews-section">
        <div class="page-head">
            <div>
                <h2>Reviews</h2>
                <p class="lede lede-flush">What other players have said.</p>
            </div>
        </div>

        <?php if ($reviews === []): ?>
            <div class="card empty">
                <p>No reviews yet.</p>
            </div>
        <?php else: ?>
            <div class="review-list">
                <?php foreach ($reviews as $review): ?>
                    <?php $author = $reviewAuthors[$review->getAuthorId()] ?? null; ?>
                    <article class="card review-card">
                        <div class="review-author">
                            <?php if ($author instanceof User && $author->getProfilePicURL() !== null): ?>
                                <img class="review-avatar" src="<?= e($author->getProfilePicURL()) ?>"
                                    alt="<?= e($author->getUsername()) ?> profile picture">
                            <?php else: ?>
                                <div class="review-avatar review-avatar-placeholder" aria-hidden="true">
                                    <?= e(strtoupper(substr($author?->getUsername() ?? '?', 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                            <strong><?= e($author?->getUsername() ?? 'Unknown user') ?></strong>
                        </div>
                        <h3><?= e($review->getTitle()) ?></h3>
                        <p><?= nl2br(e($review->getComment())) ?></p>
                        <div class="review-footer">
                            <form method="post" action="<?= e(url('review', 'vote')) ?>" class="button-row">
                                <?= $csrfField ?? '' ?>
                                <input type="hidden" name="reviewId" value="<?= e($review->getReviewId()) ?>">
                                <input type="hidden" name="targetType" value="facility">
                                <input type="hidden" name="targetId" value="<?= e($facility->getFacilityId()) ?>">
                                <button class="btn ghost small" type="submit" name="vote" value="1">Upvote</button>
                                <button class="btn ghost small" type="submit" name="vote" value="-1">Downvote</button>
                                <span class="small muted"><?= (int) $review->getVotes() ?> votes</span>
                            </form>
                            <time class="small muted" datetime="<?= e($review->getReviewTimestamp()->format(DATE_ATOM)) ?>">
                                <?= e($review->getReviewTimestamp()->format('j M Y, H:i')) ?>
                            </time>
                        </div>
                        <?php if ($isAdmin && !$review->getModerationStatus()->isRemoved()): ?>
                            <div class="button-row">
                                <form method="post" action="<?= e(url('review', 'moderate')) ?>" data-remove-review-form>
                                    <?= $csrfField ?? '' ?>
                                    <input type="hidden" name="reviewId" value="<?= e($review->getReviewId()) ?>">
                                    <input type="hidden" name="targetType" value="facility">
                                    <input type="hidden" name="targetId" value="<?= e($facility->getFacilityId()) ?>">
                                    <input type="hidden" name="moderationAction" value="toggle">

                                    <button class="btn ghost <?= $review->getModerationStatus()->isVisible() ? 'small' : '' ?>" type="submit">
                                        <?= $review->getModerationStatus()->isVisible() ? 'Mark invisible' : 'Mark visible' ?>
                                    </button>
                                </form>
                                <form method="post" action="<?= e(url('review', 'moderate')) ?>" data-remove-review-form>
                                    <?= $csrfField ?? '' ?>
                                    <input type="hidden" name="reviewId" value="<?= e($review->getReviewId()) ?>">
                                    <input type="hidden" name="targetType" value="facility">
                                    <input type="hidden" name="targetId" value="<?= e($facility->getFacilityId()) ?>">
                                    <input type="hidden" name="moderationAction" value="remove">
                                    <button class="btn danger small" type="button" data-remove-review>Mark removed</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($reviewPages > 1): ?>
                <nav class="pagination" aria-label="Review pages">
                    <?php for ($page = 1; $page <= $reviewPages; $page++): ?>
                        <a class="btn small <?= $page === $reviewPage ? '' : 'ghost' ?>"
                            href="<?= e(url('facility', 'show', ['id' => $facility->getFacilityId(), 'page' => $page])) ?>">
                            <?= $page ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$isAdmin): ?>
            <form method="post" action="<?= e(url('review', 'store')) ?>" class="card review-form">
                <?= $csrfField ?? '' ?>
                <input type="hidden" name="targetType" value="facility">
                <input type="hidden" name="targetId" value="<?= e($facility->getFacilityId()) ?>">
                <h3>Write a review</h3>
                <label for="reviewTitle">Title</label>
                <input id="reviewTitle" name="reviewTitle" maxlength="50" required>
                <label for="reviewComment">Review</label>
                <textarea id="reviewComment" name="reviewComment" rows="5" maxlength="200" required></textarea>
                <br />
                <button class="btn" type="submit">Submit review</button>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <a class="btn ghost" href="<?= e(url('auth')) ?>">Sign in to organise a game here</a>
<?php endif; ?>