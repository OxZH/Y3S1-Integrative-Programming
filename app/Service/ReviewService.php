<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Account;
use App\Model\AccountMapper;
use App\Model\FacilityMapper;
use App\Model\Review;
use App\Model\ReviewMapper;
use App\ModerationStatus;
use App\ValidationException;
use DateTimeImmutable;

final class ReviewService
{
    private const REMOVED_MESSAGE = 'This review was removed by a moderator for abusive language.';
    private const REVIEWS_PER_HOUR = 5;
    private const REVIEWS_PER_PAGE = 5;

    public function __construct(
        private ReviewMapper $reviews = new ReviewMapper(),
        private AccountMapper $accounts = new AccountMapper(),
        private FacilityMapper $facilities = new FacilityMapper()
    ) {}

    /** @return array{reviews:Review[],reviewAuthors:array<string,Account|null>,reviewPages:int} */
    public function page(string $targetType, string $targetId, int $page = 1, bool $includeModerated = false): array
    {
        $page = max(1, $page);
        $reviews = $targetType === 'facility'
            ? $this->reviews->findVisibleByFacilityId($targetId, $page, self::REVIEWS_PER_PAGE, $includeModerated)
            : $this->reviews->findVisibleByTargetUserId($targetId, $page, self::REVIEWS_PER_PAGE, $includeModerated);
        $total = $targetType === 'facility'
            ? $this->reviews->countByFacilityId($targetId, $includeModerated)
            : $this->reviews->countByTargetUserId($targetId, $includeModerated);

        $authors = [];
        foreach ($reviews as $review) {
            $authors[$review->getAuthorId()] = $this->accounts->findAccount($review->getAuthorId());
        }

        return [
            'reviews'       => $reviews,
            'reviewAuthors' => $authors,
            'reviewPages'   => max(1, (int) ceil($total / self::REVIEWS_PER_PAGE)),
        ];
    }

    /** @return Review[] */
    public function possibleSpamReviews(): array
    {
        $possibleSpamReviews = $this->reviews->findAllForSpamCheck();
        $fingerprints = [];
        $recentByAuthor = [];
        $flagged = [];
        $cutoff = new DateTimeImmutable('-1 hour');

        foreach ($possibleSpamReviews as $review) {
            $fingerprint = $review->getAuthorId() . '|' . $this->normalise($review->getTitle()) . '|' . $this->normalise($review->getComment());
            $fingerprints[$fingerprint][] = $review;

            if ($review->getReviewTimestamp() >= $cutoff) {
                $recentByAuthor[$review->getAuthorId()][] = $review;
            }
        }

        foreach ($fingerprints as $matchingReviews) {
            if (count($matchingReviews) > 1) {
                foreach ($matchingReviews as $review) {
                    $flagged[$review->getReviewId()] = $review;
                }
            }
        }

        foreach ($recentByAuthor as $recentReviews) {
            if (count($recentReviews) >= self::REVIEWS_PER_HOUR) {
                foreach ($recentReviews as $review) {
                    $flagged[$review->getReviewId()] = $review;
                }
            }
        }

        usort($flagged, static fn(Review $left, Review $right): int
        => $right->getReviewTimestamp() <=> $left->getReviewTimestamp());

        return array_values($flagged);
    }

    public function submit(
        string $authorId,
        string $targetType,
        string $targetId,
        string $title,
        string $comment
    ): void {
        $title = trim($title);
        $comment = trim($comment);
        $errors = [];

        if (!in_array($targetType, ['user', 'facility'], true) || $targetId === '') {
            $errors['target'] = 'That review target is not valid.';
        } elseif ($targetType === 'user' && $targetId === $authorId) {
            $errors['target'] = 'You cannot review your own profile.';
        } elseif ($targetType === 'user' && $this->accounts->findAccount($targetId) === null) {
            $errors['target'] = 'That profile could not be found.';
        } elseif ($targetType === 'facility' && $this->facilities->find($targetId) === null) {
            $errors['target'] = 'That facility could not be found.';
        }

        if ($title === '' || $comment === '') {
            $errors['review'] = 'A review title and comment are required.';
        } elseif (mb_strlen($title) > 50 || mb_strlen($comment) > 200) {
            $errors['review'] = 'The review title or comment is too long.';
        } elseif ($this->containsHttpUrl($title) || $this->containsHttpUrl($comment)) {
            $errors['review'] = 'Reviews cannot contain web addresses.';
        }

        if ($errors === [] && $this->reviews->countByAuthorSince($authorId, new DateTimeImmutable('-1 hour')) >= self::REVIEWS_PER_HOUR) {
            $errors['review'] = 'You can only post up to five reviews per hour.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($targetType === 'facility') {
            $this->reviews->createFacilityReview($authorId, $targetId, $title, $comment);
        } else {
            $this->reviews->createUserReview($authorId, $targetId, $title, $comment);
        }
    }

    public function vote(string $reviewId, string $voterId, string $targetType, string $targetId, int $vote): bool
    {
        if (!in_array($targetType, ['user', 'facility'], true) || $targetId === '' || !in_array($vote, [-1, 1], true)) {
            return false;
        }

        $review = $this->reviews->findById($reviewId);
        if ($review === null || !$this->belongsToTarget($review, $targetType, $targetId)) {
            return false;
        }

        $this->reviews->vote($reviewId, $voterId, $vote);

        return true;
    }

    public function moderate(string $reviewId, string $targetType, string $targetId, bool $remove): bool
    {
        $review = $this->reviews->findById($reviewId);
        if ($review === null || !$this->belongsToTarget($review, $targetType, $targetId)) {
            return false;
        }

        if ($remove) {
            $this->reviews->remove($reviewId, self::REMOVED_MESSAGE);
        } else {
            $status = $review->getModerationStatus() === ModerationStatus::VISIBLE
                ? ModerationStatus::HIDDEN
                : ModerationStatus::VISIBLE;
            $this->reviews->setModerationStatus($reviewId, $status);
        }

        return true;
    }

    private function belongsToTarget(Review $review, string $targetType, string $targetId): bool
    {
        return $targetType === 'facility'
            ? $review->getFacilityId() === $targetId
            : $review->getTargetUserId() === $targetId;
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    private function containsHttpUrl(string $value): bool
    {
        $decoded = $value;
        for ($pass = 0; $pass < 3; $pass++) {
            $next = rawurldecode(html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $normalised = str_replace('\\', '/', $decoded);
        $compact = strtolower(preg_replace('/[\s\x00-\x1F\x7F]+/u', '', $normalised) ?? $normalised);

        return preg_match('/(?:https?|hxxps?):\/{2,}/', $compact) === 1
            || preg_match('~(?:^|[\s(])//[a-z0-9.-]+(?:[/:?#]|$)~', $normalised) === 1
            || preg_match('~(?:^|[\s(])(?:www\.)?[a-z0-9-]+\.[a-z]{2,}(?::\d{1,5})?(?:[/?#\s)]|$)~i', $normalised) === 1;
    }
}
