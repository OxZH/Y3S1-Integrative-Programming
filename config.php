<?php
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
        'profile' => [
            'module' => 'User Authentication & Profile Management',
            'url'    => $stubBase . '/api/stub.php',
        ],
        'booking' => [
            'module' => 'Venue Booking & Payment',
            'url'    => $stubBase . '/api/stub.php',
        ],
        'rating' => [
            'module' => 'Social Networking & Review System',
            'url'    => $stubBase . '/api/stub.php',
        ],
        'friend' => [
            'module' => 'Social Networking & Review System',
            'url'    => $stubBase . '/api/stub.php',
        ],
    ],

    'http' => [
        'timeout'         => 5,
        'connect_timeout' => 3,
    ],
];
