<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Security\Auth;
use App\Service\ReviewService;
use App\ValidationException;

final class ReviewController extends Controller
{
    private ReviewService $reviews;

    public function __construct(?ReviewService $reviews = null)
    {
        $this->reviews = $reviews ?? new ReviewService();
    }

    public function store(): void
    {
        $this->requirePostWithCsrf();
        $current = Auth::requireLogin();
        $targetType = is_string($_POST['targetType'] ?? null) ? $_POST['targetType'] : '';
        $targetId = is_string($_POST['targetId'] ?? null) ? $_POST['targetId'] : '';
        $title = is_string($_POST['reviewTitle'] ?? null) ? $_POST['reviewTitle'] : '';
        $comment = is_string($_POST['reviewComment'] ?? null) ? $_POST['reviewComment'] : '';

        try {
            $this->reviews->submit($current->getBaseUserId(), $targetType, $targetId, $title, $comment);
            $this->flash('success', 'Your review has been posted.');
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->getErrors()));
        }

        $this->redirect($this->targetUrl($targetType, $targetId));
    }

    public function vote(): void
    {
        $this->requirePostWithCsrf();
        $current = Auth::requireLogin();
        $reviewId = is_string($_POST['reviewId'] ?? null) ? $_POST['reviewId'] : '';
        $targetType = is_string($_POST['targetType'] ?? null) ? $_POST['targetType'] : '';
        $targetId = is_string($_POST['targetId'] ?? null) ? $_POST['targetId'] : '';
        $vote = (int) ($_POST['vote'] ?? 0);

        if ($this->reviews->vote($reviewId, $current->getBaseUserId(), $targetType, $targetId, $vote)) {
            $this->flash('success', 'Your review vote was recorded.');
        }

        $this->redirect($this->targetUrl($targetType, $targetId));
    }

    public function moderate(): void
    {
        $this->requirePostWithCsrf();
        Auth::requireAdmin();

        $reviewId = is_string($_POST['reviewId'] ?? null) ? $_POST['reviewId'] : '';
        $targetType = is_string($_POST['targetType'] ?? null) ? $_POST['targetType'] : '';
        $targetId = is_string($_POST['targetId'] ?? null) ? $_POST['targetId'] : '';
        $remove = ($_POST['moderationAction'] ?? '') === 'remove';

        if ($this->reviews->moderate($reviewId, $targetType, $targetId, $remove)) {
            $this->flash('success', $remove ? 'The review was removed.' : 'The review visibility was toggled.');
        }

        $this->redirect($this->targetUrl($targetType, $targetId));
    }

    private function targetUrl(string $targetType, string $targetId): string
    {
        return $targetType === 'facility'
            ? url('facility', 'show', ['id' => $targetId])
            : url('profile', 'showOther', ['id' => $targetId]);
    }
}
