<?php
// The authentication audit trail. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Security;

use App\AuthEventType;
use App\Model\AuthEvent;
use App\Model\AuthEventMapper;
use Throwable;

/**
 * Module 2, section 5.2 threat 2: unnoticed account takeover and privilege
 * change.
 *
 * The single master routine every security event goes through. Nothing writes
 * to AuthEventLog except this class, so no entry point can log a different set
 * of fields or skip logging entirely, and there is one place to change if the
 * format has to change.
 *
 * What it will not record: passwords, reset tokens, session ids. The detail
 * column is written by us in code, never filled from a submitted value, so a
 * crafted username cannot be smuggled into the log for an admin to read back.
 */
final class AuthEventLogger
{
    private function __construct()
    {
    }

    public static function success(AuthEventType $type, ?string $baseUserId, ?string $detail = null): void
    {
        self::write($type, $baseUserId, true, null, $detail);
    }

    public static function failure(AuthEventType $type, ?string $baseUserId, ?string $detail = null): void
    {
        self::write($type, $baseUserId, false, null, $detail);
    }

    /**
     * A sign-in attempt against an address with no account. There is no
     * baseUserId to point at, so the attempted address is kept instead - that
     * is what shows a spray across many addresses from one source.
     */
    public static function failedLoginForUnknownEmail(string $email): void
    {
        self::write(AuthEventType::LOGIN_FAILED, null, false, mb_substr($email, 0, 255), 'No account with that email.');
    }

    private static function write(
        AuthEventType $type,
        ?string $baseUserId,
        bool $succeeded,
        ?string $emailTried,
        ?string $detail
    ): void {
        try {
            (new AuthEventMapper())->insert(new AuthEvent(
                uuid(),
                $baseUserId,
                $type,
                $succeeded,
                $emailTried,
                self::clientIp(),
                self::userAgent(),
                $detail === null ? null : mb_substr($detail, 0, 255)
            ));
        } catch (Throwable $e) {
            // Logging must never be the reason a sign-out or a lockout fails to
            // happen. The event goes to the error log instead and the caller
            // carries on.
            error_log(sprintf('AuthEventLogger: could not record %s: %s', $type->value, $e->getMessage()));
        }
    }

    /**
     * REMOTE_ADDR only. X-Forwarded-For is set by the client and would let an
     * attacker write whatever address they liked into our evidence. Behind a
     * real proxy this is the place to trust that header, and only for known
     * proxy addresses.
     */
    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? mb_substr($ip, 0, 45) : null;
    }

    private static function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if (!is_string($agent) || $agent === '') {
            return null;
        }

        // Control characters stripped: a log viewer should not be steered by
        // what a client sent.
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $agent) ?? '', 0, 255);
    }
}
