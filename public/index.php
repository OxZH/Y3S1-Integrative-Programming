<?php
// Front controller. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\AuthorizationException;
use App\Controller\EventController;
use App\Controller\FacilityController;
use App\Controller\LoginController;
use App\Core\View;
use App\NotFoundException;
use App\ServiceUnavailableException;

/*
 * Every controller and action is listed here. Building the class name from
 * $_GET instead would make any public method on any autoloadable class
 * reachable from the address bar.
 */
$routes = [
    'facility' => [
        'class'   => FacilityController::class,
        'actions' => ['show', 'mine', 'create', 'store', 'edit', 'update', 'suspend', 'reactivate', 'delete'],
    ],
    'event' => [
        'class'   => EventController::class,
        'actions' => ['index', 'mine', 'show', 'create', 'store', 'finalise',
                      'publish', 'cancel', 'delete',
                      'invite', 'invites', 'createInvite', 'revokeInvite'],
    ],
    'login' => [
        'class'   => LoginController::class,
        'actions' => ['index', 'login', 'logout'],
    ],
];

$controllerName = is_string($_GET['c'] ?? null) ? $_GET['c'] : 'event';
$actionName     = is_string($_GET['a'] ?? null) ? $_GET['a'] : 'index';

function renderError(int $status, string $heading, string $message, ?string $detail = null): never
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    echo View::render('error', [
        'title'   => $heading,
        'status'  => $status,
        'heading' => $heading,
        'message' => $message,
        'detail'  => $detail,
        'flash'   => [],
    ]);

    exit;
}

try {
    $route = $routes[$controllerName] ?? null;

    if ($route === null || !in_array($actionName, $route['actions'], true)) {
        renderError(404, 'Page not found', 'That address does not lead anywhere.');
    }

    (new $route['class']())->{$actionName}();
} catch (AuthorizationException $e) {
    renderError(403, 'Not allowed', $e->getMessage());
} catch (NotFoundException $e) {
    renderError(404, 'Not found', $e->getMessage());
} catch (ServiceUnavailableException $e) {
    renderError(503, 'Temporarily unavailable', $e->getMessage());
} catch (Throwable $e) {
    error_log(sprintf('Unhandled %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    renderError(
        500,
        'Something went wrong',
        'The page could not be loaded.',
        config('app.debug') ? $e::class . ': ' . $e->getMessage() : null
    );
}
