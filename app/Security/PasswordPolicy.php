<?php
// Password hashing, strength rules and lockout policy. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;

/**
 * Module 2, section 5.2 threat 1: brute force and credential stuffing.
 *
 * Every rule about a password lives here rather than being spread across the
 * registration form, the reset form and the change-password form. One file
 * decides how a password is hashed, how strong it must be and when an account
 * locks, so the three paths cannot drift apart and quietly disagree.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 200;

    /** Consecutive failures before the account stops answering. */
    public const MAX_ATTEMPTS = 5;

    /** How long a locked account stays locked. */
    public const LOCKOUT_MINUTES = 15;

    /** How long a reset link stays redeemable. */
    public const RESET_TTL_MINUTES = 30;

    /** Reset requests allowed per account per hour. */
    public const RESET_REQUESTS_PER_HOUR = 3;

    private function __construct()
    {
    }


    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * password_verify is constant time for a given hash, so the comparison
     * itself reveals nothing about how much of the password was right.
     */
    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    /** True when the stored hash used weaker settings and should be upgraded on next login. */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Length first, then variety. Returns the reason it was rejected, or null
     * when it is acceptable.
     *
     * $context holds values the password must not simply repeat - the email and
     * username - because "aisyahr2024" survives a character-class check and
     * dies instantly to a targeted guess.
     *
     * @param string[] $context
     */
    public static function reject(string $plain, array $context = []): ?string
    {
        $length = mb_strlen($plain);

        if ($length < self::MIN_LENGTH) {
            return sprintf('Password must be at least %d characters.', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            // Not a strength rule: an unbounded input is a way to make the
            // server spend real time hashing it.
            return sprintf('Password must be %d characters or fewer.', self::MAX_LENGTH);
        }

        $classes = 0;
        $classes += preg_match('/[a-z]/', $plain);
        $classes += preg_match('/[A-Z]/', $plain);
        $classes += preg_match('/\d/', $plain);
        $classes += preg_match('/[^A-Za-z0-9]/', $plain);

        if ($classes < 3) {
            return 'Password must include at least three of: lower case, upper case, a digit, a symbol.';
        }

        $lower = mb_strtolower($plain);

        foreach ($context as $value) {
            $value = trim(mb_strtolower($value));

            // The local part of an address is the part worth checking.
            if (str_contains($value, '@')) {
                $value = substr($value, 0, (int) strpos($value, '@'));
            }

            if ($value !== '' && mb_strlen($value) >= 4 && str_contains($lower, $value)) {
                return 'Password must not contain your name or email address.';
            }
        }

        if (in_array($lower, self::COMMON, true)) {
            return 'That password is too common. Please choose another.';
        }

        return null;
    }

    public static function isLocked(?string $lockedUntil, ?DateTimeImmutable $now = null): bool
    {
        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }

        $until = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lockedUntil);

        return $until !== false && $until > ($now ?? new DateTimeImmutable());
    }

    public static function lockoutEnds(): DateTimeImmutable
    {
        return new DateTimeImmutable('+' . self::LOCKOUT_MINUTES . ' minutes');
    }

    public static function resetExpiry(): DateTimeImmutable
    {
        return new DateTimeImmutable('+' . self::RESET_TTL_MINUTES . ' minutes');
    }

    /**
     * A reset token: 256 bits from a CSPRNG. random_bytes, not mt_rand or
     * uniqid, because those are predictable from earlier output.
     */
    public static function newResetToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** What goes in the table. The raw token never does. */
    public static function hashResetToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Spends roughly the time a real verification would. Called when the email
     * matched no account, so the response time of a failed login does not say
     * whether the address is registered.
     */
    public static function burnTime(): void
    {
        password_verify('not-a-real-password', '$2y$12$usesomesillystringforsalt0X8Zzt3f1H4H5UvsQoLDWpqZ1c1lRSy');
    }

    /** Rejected outright regardless of how the character classes score. */
    private const COMMON = [
        'password1!', 'password123', 'passw0rd!23', 'qwerty12345', 'welcome123!',
        'admin12345', 'letmein123!', 'iloveyou123', 'abcd1234!@', 'p@ssw0rd123',
    ];
}
