<?php
// Profile page with the participation history. Author: Ivan Lim Tze Yang

/** @var \App\Model\Account $account */
/** @var bool $isSelf */
/** @var string|null $connectionState */
/** @var array<int,array<string,mixed>> $history */
/** @var string|null $lastLoginAt */
/** @var \App\Model\AuthEvent[] $recentEvents */
/** @var \App\Model\Review[] $reviews */
/** @var array<string,\App\Model\Account|null> $reviewAuthors */
/** @var int $reviewPage */
/** @var int $reviewPages */

use App\Model\Admin;
use App\Model\FacilityOwner;
use App\Model\User;
?>
<div class="page-head">
    <div>
        <h1><?= e($account->getUsername()) ?></h1>
        <p class="lede-flush">
            <span class="pill live"><?= e($account->getUserType()->label()) ?></span>
            <span class="pill <?= $account->isActive() ? 'live' : 'dead' ?>">
                <?= e($account->getAccountStatus()->label()) ?>
            </span>
        </p>
    </div>
    <?php if (!$isSelf): ?>
        <form method="post" action="<?= e(url('profile', 'sendFriendRequest')) ?>">
            <?= $csrfField ?? '' ?>
            <input type="hidden" name="userId" value="<?= e($account->getBaseUserId()) ?>">

            <?php if ($connectionState === 'PENDING'): ?>
                <button class="btn ghost" type="submit" disabled>Friend request pending</button>
            <?php elseif ($connectionState === 'ACCEPTED'): ?>
                <button class="btn ghost" type="submit" disabled>Friends</button>
            <?php else: ?>
                <button class="btn" type="submit">Add friend</button>
            <?php endif; ?>

        </form>
    <?php endif; ?>
    <?php if ($isSelf): ?>
        <div class="toolbar">
            <a class="btn" href="<?= e(url('profile', 'edit')) ?>">Edit profile</a>
            <a class="btn ghost" href="<?= e(url('profile', 'security')) ?>">Password &amp; security</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($isSelf && $lastLoginAt !== null): ?>
    <div class="banner">
        Last signed in <strong><?= e($lastLoginAt) ?></strong>.
        If that was not you, change your password.
    </div>
<?php endif; ?>

<div class="row">
    <div class="card">
        <h2>Account</h2>
        <?php if ($account->getEmail() && $account->getContactNumber()): ?>
            <p class="stat"><span class="sub-tight">Email</span><br><?= e($account->getEmail()) ?></p>
            <p class="stat"><span class="sub-tight">Contact number</span><br><?= e($account->getContactNumber()) ?></p>
        <?php endif; ?>

        <p class="stat"><span class="sub-tight">Member since</span><br>
            <?= e($account->getRegisterTime()?->format('j M Y') ?? 'Unknown') ?>
        </p>

        <?php if ($account instanceof User): ?>
            <p class="stat"><span class="sub-tight">Favourite sport</span><br>
                <?= e($account->getFavoriteSport() ?? 'Not set') ?>
            </p>
            <p class="stat"><span class="sub-tight">Plays around</span><br>
                <?= e($account->getLocation() ?? 'Not set') ?>
            </p>
            <p class="stat"><span class="sub-tight">Age</span><br>
                <?= $account->getAge() !== null ? (int) $account->getAge() . ' years' : 'Not set' ?>
            </p>
        <?php endif; ?>

        <?php if ($account instanceof FacilityOwner): ?>
            <p class="stat"><span class="sub-tight">Bank</span><br><?= e($account->getBankName()) ?></p>
            <p class="stat"><span class="sub-tight">Bank account</span><br>
                <span class="mono"><?= e($account->getMaskedBankAccountNum()) ?></span>
            </p>
            <p class="stat"><span class="sub-tight">Business registration</span><br>
                <?= e($account->getBusinessRegNum()) ?>
            </p>
            <p class="small muted">
                The account number is shown masked. The full value is never sent to the
                browser and never leaves the server except to the payment module.
            </p>
        <?php endif; ?>

        <?php if ($account instanceof Admin): ?>
            <p class="stat"><span class="sub-tight">Staff number</span><br>
                <span class="mono"><?= e($account->getAdminId()) ?></span>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($account instanceof User): ?>
        <div class="card">
            <h2>Recently participated</h2>
            <p class="small muted">
                The event names below come from the Event &amp; Facility module's
                <span class="mono">getEventDetails</span> web service, not from this
                module's own tables.
            </p>

            <?php if ($history === []): ?>
                <p class="empty">No events joined yet.</p>
            <?php else: ?>
                <table class="card-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Sport</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td>
                                    <?= e((string) $row['name']) ?>
                                    <?php if ($row['available'] !== true): ?>
                                        <span class="pill wait">unavailable</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $row['sport']) ?></td>
                                <td><?= e((string) $row['eventDate']) ?></td>
                                <td><span class="pill"><?= e((string) $row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

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
                            <input type="hidden" name="targetType" value="user">
                            <input type="hidden" name="targetId" value="<?= e($account->getBaseUserId()) ?>">
                            <button class="btn ghost small" type="submit" name="vote" value="1">Upvote</button>
                            <button class="btn ghost small" type="submit" name="vote" value="-1">Downvote</button>
                            <span class="small muted"><?= (int) $review->getVotes() ?> votes</span>
                        </form>
                        <time class="small muted" datetime="<?= e($review->getReviewTimestamp()->format(DATE_ATOM)) ?>">
                            <?= e($review->getReviewTimestamp()->format('j M Y, H:i')) ?>
                        </time>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($reviewPages > 1): ?>
            <nav class="pagination" aria-label="Review pages">
                <?php for ($page = 1; $page <= $reviewPages; $page++): ?>
                    <a class="btn small <?= $page === $reviewPage ? '' : 'ghost' ?>"
                        href="<?= e(url('profile', 'showOther', ['id' => $account->getBaseUserId(), 'page' => $page])) ?>">
                        <?= $page ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isSelf): ?>
        <form method="post" action="<?= e(url('review', 'store')) ?>" class="card review-form">
            <?= $csrfField ?? '' ?>
            <input type="hidden" name="targetType" value="user">
            <input type="hidden" name="targetId" value="<?= e($account->getBaseUserId()) ?>">
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