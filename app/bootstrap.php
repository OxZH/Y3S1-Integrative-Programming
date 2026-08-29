<?php
// Bootstrap: autoloader, configuration, session. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// These hold several types each, so the autoloader cannot find them by name.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/Exceptions.php';

$GLOBALS['config'] = require dirname(__DIR__) . '/config.php';

if (config('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    // A stack trace names file paths, tables and query fragments. Log it instead.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

date_default_timezone_set('Asia/Kuala_Lumpur');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // httponly keeps the session id away from JavaScript, samesite stops the
    // browser sending it on a cross-site POST.
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
