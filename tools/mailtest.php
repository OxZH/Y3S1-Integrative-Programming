<?php
/**
 * Sends one test email, to check the mail settings in .env work.
 * Author: Ivan Lim Tze Yang
 *
 *   php tools\mailtest.php you@example.com
 *
 * It reports what it is about to do, sends, and says what the server replied.
 * Nothing here touches the database, and it prints no password.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Service\Mailer;

$to = $argv[1] ?? '';

if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    echo "Usage: php tools\\mailtest.php you@example.com\n";
    exit(1);
}

$host = (string) config('mail.host', '');

if ($host === '') {
    echo "No mail server is configured.\n\n";
    echo "  Copy .env.example to .env, fill in the SP_MAIL_* values, and run this again.\n";
    echo "  Until then the password reset writes its link to storage\\mail.log instead.\n";
    exit(1);
}

$password = (string) config('mail.password', '');

echo "Sending a test email\n";
printf("  server     : %s:%d (%s)\n", $host, (int) config('mail.port'), (string) config('mail.encryption'));
printf("  username   : %s\n", (string) config('mail.username', '(none - no authentication)'));
printf("  password   : %s\n", $password === '' ? '(none)' : str_repeat('*', strlen($password)) . ' (' . strlen($password) . ' chars)');
printf("  from       : %s\n", (string) config('mail.from'));
printf("  to         : %s\n\n", $to);

// A Gmail app password is 16 characters once the display spaces are removed.
if ($password !== '' && str_contains($host, 'gmail') && strlen($password) !== 16) {
    echo "  Note: Gmail app passwords are 16 characters. Yours is " . strlen($password) . ".\n";
    echo "        If it was copied with spaces those are removed automatically, so this\n";
    echo "        probably means the normal account password was used instead of an app\n";
    echo "        password - Gmail will refuse that.\n\n";
}

$sent = Mailer::send(
    $to,
    'SportsPlatform test email',
    "This is a test from SportsPlatform.\n\nIf you are reading it, the password reset will reach real inboxes.",
    '<p>This is a test from <strong>SportsPlatform</strong>.</p>'
    . '<p>If you are reading it, the password reset will reach real inboxes.</p>'
);

if ($sent) {
    echo "Sent. Check the inbox (and the spam folder - a first message from a new\n";
    echo "sender often lands there).\n";
    exit(0);
}

echo "Not sent. The reason was written to the PHP error log; the last lines are\n";
echo "usually enough to tell what went wrong:\n\n";
echo "  535 / authentication failed  -> wrong username or app password\n";
echo "  Could not reach ...          -> wrong host or port, or a firewall\n";
echo "  would not start TLS          -> try SP_MAIL_ENCRYPTION=ssl with port 465\n";
exit(1);
