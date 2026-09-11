<?php
// Standalone front controller for Venue Booking & Payment. Author: Khor Zhi Hong

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\AuthorizationException;
use App\Controller\PaymentController;
use App\Core\View;
use App\NotFoundException;
use App\ServiceUnavailableException;

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'index';
$actions = [
    'index', 'venue', 'participant', 'confirm', 'cancelParticipant',
];

function paymentPageError(int $status, string $heading, string $message, ?string $detail = null): never
{
    http_response_code($status);
    echo View::render('error', [
        'title' => $heading,
        'status' => $status,
        'heading' => $heading,
        'message' => $message,
        'detail' => $detail,
        'flash' => [],
    ]);
    exit;
}

try {
    if (!in_array($action, $actions, true)) {
        paymentPageError(404, 'Page not found', 'That payment address does not exist.');
    }

    (new PaymentController())->{$action}();
} catch (AuthorizationException $e) {
    paymentPageError(403, 'Not allowed', $e->getMessage());
} catch (NotFoundException $e) {
    paymentPageError(404, 'Not found', $e->getMessage());
} catch (ServiceUnavailableException $e) {
    paymentPageError(503, 'Temporarily unavailable', $e->getMessage());
} catch (Throwable $e) {
    error_log('payment.php: ' . $e->getMessage());
    paymentPageError(
        500,
        'Something went wrong',
        'The payment page could not be loaded.',
        config('app.debug') ? $e::class . ': ' . $e->getMessage() : null
    );
}
