<?php
// Delivers a password reset link. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Domain;

use App\Model\Account;
use App\Security\PasswordPolicy;
use App\Service\Mailer;

/**
 * Sends the reset link by email when the site has been given mail credentials,
 * and writes it to a local file when it has not.
 *
 * The fallback is what makes a fresh clone work: a teammate with no
 * SMTP account still gets a working reset flow, with the link appended to
 * storage/mail.log - which sits outside public/, so it cannot be fetched over
 * HTTP the way anything under public/ can.
 *
 * The rule that matters holds either way: the link goes to the address already
 * on the account. It is never sent to an address supplied with the request,
 * which is exactly what would let somebody redirect a stranger's reset to
 * themselves.
 */
final class ResetLinkDelivery
{
    /** Where the link is kept for the on-screen fallback when no mail is configured. */
    public const SESSION_KEY = '_reset_link_fallback';

    private function __construct()
    {
    }

    public static function send(Account $account, string $rawToken): void
    {
        $link = rtrim((string) config('app.base_url'), '/')
            . '/' . url('auth', 'resetForm', ['token' => $rawToken]);

        $sent = false;

        if (Mailer::isConfigured()) {
            $sent = Mailer::send(
                $account->getEmail(),
                'Reset your SportsPlatform password',
                self::plainText($account, $link),
                self::html($account, $link)
            );
        }

        // Always written, even when the mail went out. If a reset is disputed
        // later, the log says a link was issued and when.
        self::writeToMailbox($account->getEmail(), $link, $sent);

        // Only worth keeping when there is no mail server to deliver it, and
        // only while debugging. Once mail works the link belongs in the inbox
        // and nowhere else, so it is not held in the session at all.
        if (!$sent && !Mailer::isConfigured() && config('app.debug')) {
            $_SESSION[self::SESSION_KEY] = $link;
        }
    }

    public static function takeFallbackLink(): ?string
    {
        $link = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        return is_string($link) && $link !== '' ? $link : null;
    }

    private static function plainText(Account $account, string $link): string
    {
        return implode("\n", [
            'Hello ' . $account->getUsername() . ',',
            '',
            'Someone asked to reset the password on your SportsPlatform account.',
            'Open the link below to choose a new one:',
            '',
            $link,
            '',
            sprintf(
                'The link works once and expires in %d minutes.',
                PasswordPolicy::RESET_TTL_MINUTES
            ),
            '',
            'If this was not you, you can ignore this email. Your password has',
            'not changed, and nobody can use the link without opening it.',
            '',
            'SportsPlatform',
        ]);
    }

    /**
     * Deliberately plain HTML with inline styles. Mail clients strip <style>
     * blocks and understand almost no modern CSS, so anything cleverer would
     * arrive looking worse than this does.
     */
    private static function html(Account $account, string $link): string
    {
        $name    = e($account->getUsername());
        $safeUrl = e($link);
        $minutes = (int) PasswordPolicy::RESET_TTL_MINUTES;

        return <<<HTML
        <div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;color:#1f2937;line-height:1.5">
          <p>Hello {$name},</p>
          <p>Someone asked to reset the password on your SportsPlatform account.</p>
          <p>
            <a href="{$safeUrl}"
               style="display:inline-block;padding:11px 18px;background:#15803d;color:#ffffff;
                      text-decoration:none;border-radius:6px;font-weight:600">
              Choose a new password
            </a>
          </p>
          <p style="color:#6b7280;font-size:13px">
            The link works once and expires in {$minutes} minutes.
          </p>
          <p style="color:#6b7280;font-size:13px">
            If the button does not work, copy this address into your browser:<br>
            <span style="word-break:break-all">{$safeUrl}</span>
          </p>
          <p style="color:#6b7280;font-size:13px">
            If this was not you, you can ignore this email. Your password has not
            changed.
          </p>
          <p style="color:#6b7280;font-size:13px">SportsPlatform</p>
        </div>
        HTML;
    }

    /**
     * Appends to storage/mail.log, outside the web root. Stands in for the
     * inbox when no mail server is configured, and is a record that a link was
     * issued when one is.
     */
    private static function writeToMailbox(string $email, string $link, bool $sent): void
    {
        $directory = dirname(__DIR__, 2) . '/storage';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('ResetLinkDelivery: could not create the storage directory.');

            return;
        }

        $entry = sprintf(
            "[%s] %s\nTo: %s\nSubject: Reset your SportsPlatform password\n%s\nExpires in %d minutes, single use.\n\n",
            date('Y-m-d H:i:s'),
            $sent ? 'SENT by email' : 'NOT SENT - no mail server configured, link recorded here only',
            $email,
            $link,
            PasswordPolicy::RESET_TTL_MINUTES
        );

        if (file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
            error_log('ResetLinkDelivery: could not write the reset link to the mailbox file.');
        }
    }
}
