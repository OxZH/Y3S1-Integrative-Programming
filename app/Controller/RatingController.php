<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Security\Auth;
use App\Service\RatingService;
use App\ValidationException;

final class RatingController extends Controller
{
    public function __construct(private ?RatingService $ratings = null)
    {
        $this->ratings ??= new RatingService();
    }

    public function store(): void
    {
        $this->requirePostWithCsrf();
        $current = Auth::requireLogin();
        $targetType = is_string($_POST['targetType'] ?? null) ? $_POST['targetType'] : '';
        $targetId = is_string($_POST['targetId'] ?? null) ? $_POST['targetId'] : '';
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        $attitudeRating = filter_var($_POST['attitudeRating'] ?? null, FILTER_VALIDATE_INT);
        $attendanceRating = filter_var($_POST['attendanceRating'] ?? null, FILTER_VALIDATE_INT);

        try {
            if ($targetType === 'user') {
                if ($attitudeRating === false || $attendanceRating === false) {
                    throw new ValidationException(['rating' => 'Please select both ratings.']);
                }
                $this->ratings->rateUser($current->getBaseUserId(), $targetId, $attitudeRating, $attendanceRating);
            } elseif ($rating === false) {
                throw new ValidationException(['rating' => 'A rating must be between one and five stars.']);
            } else {
                if ($targetType !== 'facility') {
                    throw new ValidationException(['target' => 'That rating target is not valid.']);
                }
                $this->ratings->rateFacility($current->getBaseUserId(), $targetId, $rating);
            }
            $this->flash('success', 'Your rating was saved.');
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->getErrors()));
        }

        $this->redirect($targetType === 'facility'
            ? url('facility', 'show', ['id' => $targetId])
            : url('profile', 'showOther', ['id' => $targetId]));
    }
}
