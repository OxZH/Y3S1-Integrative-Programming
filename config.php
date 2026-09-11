<?php
$envFile = __DIR__ . '/.env';

if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $key => $value) {
        // parse_ini_file only treats ';' as a comment, not '#'. Everyone writes
        // .env comments with '#', so a line like
        //     # set SP_MAIL_HOST=... to turn mail on
        // arrives here as a key of its own, and one whose text happened to match
        // a real name would quietly override it. Only well-formed names are
        // taken; anything else was a comment.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) !== 1) {
            continue;
        }

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

// Under Apache the endpoints sit on the same host and this just works. Under
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

    // Services this module consumes. Every one now points at the real endpoint
    // of the module that owns it; the api/stub.php stand-in has been removed.
    'services' => [
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

    // Outgoing mail, used by the password reset. Everything comes from .env,
    // which is not in the repository - a mail password does not belong in a
    // file everyone can read.
    //
    // Leave SP_MAIL_HOST unset and nothing is sent: the reset link is written
    // to storage/mail.log instead, which is how the demo runs without anyone
    // needing mail credentials. See .env.example.
    'mail' => [
        'host'       => getenv('SP_MAIL_HOST') ?: '',
        'port'       => (int) (getenv('SP_MAIL_PORT') ?: 587),
        // 'tls' upgrades a plain connection with STARTTLS (port 587).
        // 'ssl' is encrypted from the first byte (port 465).
        'encryption' => getenv('SP_MAIL_ENCRYPTION') ?: 'tls',
        'username'   => trim(getenv('SP_MAIL_USERNAME') ?: ''),
        // Google shows an app password as "abcd efgh ijkl mnop" for readability,
        // and it is the 16 characters without the spaces that actually work.
        // Pasting it as displayed is the usual reason authentication fails, so
        // the spaces come out here rather than catching everyone out.
        'password'   => str_replace(' ', '', trim(getenv('SP_MAIL_PASSWORD') ?: '')),
        'from'       => getenv('SP_MAIL_FROM') ?: 'no-reply@sportsplatform.my',
        'from_name'  => getenv('SP_MAIL_FROM_NAME') ?: 'SportsPlatform',
    ],
];
