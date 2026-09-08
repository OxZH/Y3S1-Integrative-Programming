<?php
// Delivers a password reset link. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Domain;

use App\Model\Account;

/**
 * There is no mail server behind a XAMPP demo, so the link is written to a local
 * file that stands in for the user's inbox, and - only while app.debug is on -
 * handed to the screen so the flow can be demonstrated.
 *
 * The important rule is kept either way: the link is addressed to the address
 * already on the account. It is never sent to an address supplied with the
 * request, which is what would let someone redirect a stranger's reset to
 * themselves.
 *
 * Replacing this class with a real mailer is the only change production needs.
 */
final class ResetLinkDelivery
{
    /** Where the last link is put on screen for the demo. Debug builds only. */
    public const SESSION_KEY = '_demo_reset_link';

    private function __construct()
    {
    }

    public static function send(Account $account, string $rawToken): void
    {
        $link = rtrim((string) config('app.base_url'), '/')
            . '/' . url('auth', 'resetForm', ['token' => $rawToken]);

        self::writeToMailbox($account->getEmail(), $link);

        // Never on a live build: the whole point of the token is that only the
        // inbox owner sees it.
        if (config('app.debug')) {
            $_SESSION[self::SESSION_KEY] = $link;
        }
    }

    public static function takeDemoLink(): ?string
    {
        $link = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        return is_string($link) && $link !== '' ? $link : null;
    }

    /**
     * Appends to storage/mail.log, outside the web root, so the file cannot be
     * fetched over HTTP the way anything under public/ can.
     */
    private static function writeToMailbox(string $email, string $link): void
    {
        $directory = dirname(__DIR__, 2) . '/storage';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('ResetLinkDelivery: could not create the storage directory.');

            return;
        }

        $entry = sprintf(
            "[%s] To: %s\nSubject: Reset your SportsPlatform password\n%s\nThis link expires in %d minutes and can be used once.\n\n",
            date('Y-m-d H:i:s'),
            $email,
            $link,
            \App\Security\PasswordPolicy::RESET_TTL_MINUTES
        );

        if (file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
            error_log('ResetLinkDelivery: could not write the reset link to the mailbox file.');
        }
    }
}
