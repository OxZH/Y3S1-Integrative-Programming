<?php
// Cross-site request forgery tokens. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Security;

use App\AuthorizationException;

/**
 * A browser attaches the session cookie because of where a request is going,
 * not where it came from, so a form on another site can make a logged-in owner's
 * browser send an authenticated POST that drops their venue price to zero. What
 * is missing is proof of intent, which the token supplies: the attacker's page
 * can send the request but the same-origin policy stops it reading ours to learn
 * the token.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    private const FIELD_NAME  = '_token';

    private function __construct()
    {
    }

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            e(self::token())
        );
    }

    public static function isValid(?string $submitted): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($submitted)) {
            return false;
        }

        // hash_equals, not ===, so the comparison time gives nothing away.
        return hash_equals($expected, $submitted);
    }

    /** @param array<string,mixed> $request */
    public static function check(array $request): void
    {
        $submitted = $request[self::FIELD_NAME] ?? null;

        if (!self::isValid(is_string($submitted) ? $submitted : null)) {
            throw new AuthorizationException(
                'This form has expired or was not submitted from this site. Please reload and try again.'
            );
        }
    }

    // Called on login and logout, so a token captured before a privilege change
    // cannot be replayed after it.
    public static function rotate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
