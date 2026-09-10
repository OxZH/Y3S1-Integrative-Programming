<?php
// Possible spam review queue for administrators.

/** @var \App\Model\Review[] $reviews */
/** @var array<string,\App\Model\Account|null> $reviewAuthors */
?>
<div class="page-head">
    <div>
        <h1>Possible spam reviews</h1>
        <p class="lede-flush">Reviews flagged by repeated content or burst posting.</p>
    </div>
    <div class="toolbar">
        <a class="btn ghost" href="<?= e(url('admin', 'accounts')) ?>">Accounts</a>
        <a class="btn ghost" href="<?= e(url('admin', 'audit')) ?>">Security log</a>
    </div>
</div>

<?php if ($reviews === []): ?>
    <div class="card empty">
        <p>No possible spam reviews found.</p>
    </div>
<?php else: ?>
    <div class="review-list">
        <?php foreach ($reviews as $review): ?>
            <?php
            $author = $reviewAuthors[$review->getAuthorId()] ?? null;
            $targetType = $review->getFacilityId() !== null ? 'facility' : 'user';
            $targetId = $review->getFacilityId() ?? $review->getTargetUserId();
            ?>
            <article class="card review-card">
                <div class="review-author">
                    <strong><?= e($author?->getUsername() ?? 'Unknown user') ?></strong>
                    <span class="pill <?= $review->getModerationStatus()->isVisible() ? 'live' : 'dead' ?>">
                        <?= e($review->getModerationStatus()->label()) ?>
                    </span>
                    <a class="btn ghost small review-target-link" href="<?= e(
                                                                            $targetType === 'facility'
                                                                                ? url('facility', 'show', ['id' => $targetId])
                                                                                : url('profile', 'showOther', ['id' => $targetId])
                                                                        ) ?>">View review</a>
                </div>
                <p class="small muted">
                    <?= e(ucfirst($targetType)) ?> target: <span class="mono"><?= e((string) $targetId) ?></span>
                </p>
                <h2><?= e($review->getTitle()) ?></h2>
                <p><?= nl2br(e($review->getComment())) ?></p>
                <div class="review-footer">
                    <time class="small muted" datetime="<?= e($review->getReviewTimestamp()->format(DATE_ATOM)) ?>">
                        <?= e($review->getReviewTimestamp()->format('j M Y, H:i')) ?>
                    </time>
                    <div class="button-row">
                        <form method="post" action="<?= e(url('review', 'moderate')) ?>">
                            <?= $csrfField ?? '' ?>
                            <input type="hidden" name="reviewId" value="<?= e($review->getReviewId()) ?>">
                            <input type="hidden" name="targetType" value="<?= e($targetType) ?>">
                            <input type="hidden" name="targetId" value="<?= e((string) $targetId) ?>">
                            <input type="hidden" name="moderationAction" value="toggle">
                            <button class="btn ghost small" type="submit">
                                <?= $review->getModerationStatus()->isVisible() ? 'Mark invisible' : 'Mark visible' ?>
                            </button>
                        </form>
                        <form method="post" action="<?= e(url('review', 'moderate')) ?>">
                            <?= $csrfField ?? '' ?>
                            <input type="hidden" name="reviewId" value="<?= e($review->getReviewId()) ?>">
                            <input type="hidden" name="targetType" value="<?= e($targetType) ?>">
                            <input type="hidden" name="targetId" value="<?= e((string) $targetId) ?>">
                            <input type="hidden" name="moderationAction" value="remove">
                            <button class="btn danger small" type="submit">Mark removed</button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>