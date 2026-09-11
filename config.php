<?php
$envFile = __DIR__ . '/.env';

if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $key => $value) {
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}
// Application configuration. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

// Worked out from the request, so the same file runs under Apache at
// /sportsplatform, under a virtual host at the root, or under `php -S`.
// Override with SP_BASE_URL if needed.
$baseUrl = getenv('SP_BASE_URL') ?: 'http://localhost:8000';

if (getenv('SP_BASE_URL') === false && isset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'])) {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

    // The service endpoints live one level deeper, in public/api.
    if (basename($dir) === 'api') {
        $dir = dirname($dir);
    }

    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($dir, '/');
}

// Under Apache the stubs sit on the same host and this just works. Under
// `php -S` it must point somewhere else: that server is single threaded, so a
// page calling its own host waits on a request it is itself blocking.
$stubBase = getenv('SP_STUB_BASE') ?: $baseUrl;
$serviceKey = getenv('SP_PAYMENT_SERVICE_KEY')
    ?: hash('sha256', 'local-payment-service|' . __DIR__);

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'sports_platform',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'base_url' => $baseUrl,
        'module'   => 'Event & Facility Management',
        'debug'    => true,
    ],

    // Services this module consumes. Each points at the stub until the real
    // module is ready; then only the url changes.
    'services' => [
        // Module 2 is built, so this one is no longer a stub: it points at the
        // real endpoint. Nothing in module 1 changed - only this url did.
        'profile' => [
            'module' => 'User Authentication & Profile Management',
            'url'    => $stubBase . '/api/user.php',
        ],

        'booking' => [
            'module' => 'Venue Booking & Payment',
            'url'    => $baseUrl . '/api/payment.php',
            'key'    => $serviceKey,
        ],
        // Consumed BY module 2: the participation history on a profile asks the
        // Event & Facility module to describe each event the user joined.
        'event' => [
            'module' => 'Event & Facility Management',
            'url'    => $baseUrl . '/api/event.php',
        ],
        'facility' => [
            'module' => 'Event & Facility Management',
            'url'    => $baseUrl . '/api/facility.php',
        ],

        // Consumed BY module 2: the participation history on a profile asks the
        // Discovery module for the events a user joined, already joined up with
        // what each event is and filtered for what the viewer may see.
        'discovery' => [
            'module' => 'Discovery & Event Matchmaking',
            'url'    => $baseUrl . '/api/discovery.php',
        ],

        'rating' => [
            'module' => 'Social Networking & Review System',
            'url'    => $baseUrl . '/api/facility.php',
        ],
        'friend' => [
            'module' => 'Social Networking & Review System',
            'url'    => $baseUrl . '/api/user.php',
        ],
    ],

    'http' => [
        'timeout'         => 5,
        'connect_timeout' => 3,
    ],

    'payment' => [
        'currency'    => 'myr',
        'service_key' => $serviceKey,
    ],
];
