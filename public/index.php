<?php
// Front controller. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\AuthorizationException;
use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\DiscoveryController;
use App\Controller\EventController;
use App\Controller\FacilityController;
use App\Controller\LocationController;
use App\Controller\ProfileController;
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
        'actions' => ['search', 'show', 'mine', 'create', 'store', 'edit', 'update', 'suspend', 'reactivate', 'delete'],
    ],
    'event' => [
        'class'   => EventController::class,
        // js part - 'index' was the temporary Upcoming games listing, now removed.
        // Find a game is the browse page; everything else here is unchanged.
        'actions' => ['mine', 'show', 'create', 'store', 'finalise',
                      'publish', 'cancel', 'complete', 'delete',
                      'invite', 'invites', 'createInvite', 'revokeInvite'],
    ],
    // MODULE 2 - User Authentication & Profile Management (Ivan)
    'auth' => [
        'class'   => AuthController::class,
        'actions' => ['index', 'login', 'logout', 'register', 'store',
                      'forgot', 'sendReset', 'resetForm', 'reset'],
    ],
    'profile' => [
        'class'   => ProfileController::class,
        'actions' => ['index', 'edit', 'update', 'security', 'changePassword', 'deactivate'],
    ],
    'admin' => [
        'class'   => AdminController::class,
        'actions' => ['accounts', 'reactivate', 'audit'],
    ],
    'location' => [
        'class'   => LocationController::class,
        'actions' => ['lookup'],
    ],
    // MODULE 5 - Discovery & Event Matchmaking (js)
    'discovery' => [
        'class'   => DiscoveryController::class,
        'actions' => ['index', 'map', 'recommended', 'join', 'leave', 'mine'],
    ],
];

// The demo account picker this module replaced. Anything still pointing at
// ?c=login lands on the real sign-in page instead of a 404.
if (($_GET['c'] ?? null) === 'login') {
    $_GET['c'] = 'auth';
}

// js part - the site opened on the temporary Upcoming games listing. With that
// page going, an address with no controller lands on Find a game instead.
$controllerName = is_string($_GET['c'] ?? null) ? $_GET['c'] : 'discovery';
$actionName     = is_string($_GET['a'] ?? null) ? $_GET['a'] : 'index';

function renderError(int $status, string $heading, string $message, ?string $detail = null): never
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    // The normal error page is a normal page: it draws the layout, and the
    // layout asks Auth::user() who is signed in, which reads the database. So
    // when the thing that failed IS the database, rendering the error page
    // fails too - and that second failure has nothing left to catch it, so the
    // browser gets a stack trace instead of the tidy page we meant to send.
    //
    // Hence the fallback: if the page cannot be drawn, answer with plain HTML
    // that needs nothing at all. The handler always manages to say something.
    try {
        echo View::render('error', [
            'title'   => $heading,
            'status'  => $status,
            'heading' => $heading,
            'message' => $message,
            'detail'  => $detail,
            'flash'   => [],
        ]);
    } catch (Throwable $e) {
        error_log('Error page itself failed: ' . $e->getMessage());

        echo '<!doctype html><meta charset="utf-8">'
           . '<title>' . htmlspecialchars($heading, ENT_QUOTES) . '</title>'
           . '<h1>' . htmlspecialchars($heading, ENT_QUOTES) . '</h1>'
           . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
    }

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
} catch (PDOException $e) {
    // Caught separately and never shown, not even while debugging. A database
    // message names our tables, columns and constraints, which is a free map of
    // the schema for anyone probing the site. It goes to the error log, where
    // only we can read it.
    error_log(sprintf('Database error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));

    renderError(500, 'Something went wrong', 'We could not save that. Please try again.');
} catch (Throwable $e) {
    error_log(sprintf('Unhandled %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    renderError(
        500,
        'Something went wrong',
        'The page could not be loaded.',
        config('app.debug') ? $e::class . ': ' . $e->getMessage() : null
    );
}
