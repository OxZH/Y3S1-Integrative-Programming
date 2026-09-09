<?php
// Protection proxy over AccountService. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Domain;

use App\AuthorizationException;
use App\AuthEventType;
use App\Model\Account;
use App\Model\AuthEvent;
use App\Security\Auth;
use App\Security\AuthEventLogger;

/**
 * PROXY (structural) - module 2's design pattern.
 *
 *   AccountServiceInterface  (Subject)   the operations, named once
 *   AccountService           (RealSubject) does the work, trusts its caller
 *   AccountServiceProxy      (Proxy)     same interface, decides who may ask
 *
 * Why a proxy rather than putting the checks inside AccountService: the two
 * answer different questions. AccountService answers "change this password".
 * The proxy answers "may this person change that password". Keeping them apart
 * means the rules live in one readable file instead of being repeated at the
 * top of every method, and AccountService can be reasoned about without a
 * session existing at all.
 *
 * Why not put the checks in the controller: because then every new controller,
 * every new route and every web service endpoint would have to remember them.
 * Controllers are handed the proxy and only the proxy, so the checks are not
 * something a caller can forget - there is no way to reach the real service
 * except through here.
 *
 * It also does the two other jobs a proxy is for:
 *   - lazy instantiation: the real service, and its three mappers, are not
 *     built until an operation actually needs them
 *   - logging: a refusal is recorded, so probing shows up in the audit trail
 *
 * Access rule, in one sentence: you may act on your own account, an
 * administrator may act on any account, and everything else is refused with the
 * same message whether the account exists or not.
 */
final class AccountServiceProxy implements AccountServiceInterface
{
    private ?AccountService $service = null;

    public function __construct(private ?AccountService $injected = null)
    {
    }

    // ------------------------------------------------------- open operations
    // No session is required to reach these: they are how someone gets one.

    /** @param array<string,mixed> $validated */
    public function register(array $validated): Account
    {
        return $this->real()->register($validated);
    }

    public function authenticate(string $email, string $plainPassword): Account
    {
        return $this->real()->authenticate($email, $plainPassword);
    }

    public function requestPasswordReset(string $email): void
    {
        $this->real()->requestPasswordReset($email);
    }

    public function resetPassword(string $rawToken, string $newPassword): void
    {
        // The token is the authorisation. Someone resetting a password is by
        // definition not signed in, so there is no session to check.
        $this->real()->resetPassword($rawToken, $newPassword);
    }

    // ------------------------------------------------------ guarded operations

    public function viewProfile(string $baseUserId): Account
    {
        $this->assertSelfOrAdmin($baseUserId, 'view that profile');

        return $this->real()->viewProfile($baseUserId);
    }

    /** @param array<string,mixed> $validated */
    public function updateProfile(string $baseUserId, array $validated): Account
    {
        $this->assertSelfOrAdmin($baseUserId, 'edit that profile');

        return $this->real()->updateProfile($baseUserId, $validated);
    }

    public function changePassword(string $baseUserId, string $currentPassword, string $newPassword): void
    {
        // Stricter than self-or-admin on purpose. Changing a password needs the
        // current one, which an administrator does not have, so an admin cannot
        // quietly take over an account through this door. Suspending it and
        // letting the owner recover is the supported path.
        $this->assertSelf($baseUserId, 'change that password');

        $this->real()->changePassword($baseUserId, $currentPassword, $newPassword);
    }

    public function deactivate(string $baseUserId, string $currentPassword): void
    {
        $this->assertSelf($baseUserId, 'deactivate that account');

        $this->real()->deactivate($baseUserId, $currentPassword);
    }

    public function reactivate(string $baseUserId): void
    {
        $this->assertAdmin('reactivate an account');

        $this->real()->reactivate($baseUserId);
    }

    /** @return Account[] */
    public function listAccounts(): array
    {
        $this->assertAdmin('list accounts');

        return $this->real()->listAccounts();
    }

    /** @return AuthEvent[] */
    public function securityHistory(string $baseUserId, int $limit = 20): array
    {
        $this->assertSelfOrAdmin($baseUserId, 'read that security history');

        return $this->real()->securityHistory($baseUserId, $limit);
    }

    // ---------------------------------------------------------------- the rules

    private function assertSelf(string $baseUserId, string $what): void
    {
        $current = Auth::id();

        if ($current === null || !hash_equals($current, $baseUserId)) {
            $this->refuse($what, $baseUserId);
        }
    }

    private function assertSelfOrAdmin(string $baseUserId, string $what): void
    {
        $current = Auth::id();

        if ($current !== null && hash_equals($current, $baseUserId)) {
            return;
        }

        if (Auth::user()?->isAdmin() === true) {
            return;
        }

        $this->refuse($what, $baseUserId);
    }

    private function assertAdmin(string $what): void
    {
        if (Auth::user()?->isAdmin() !== true) {
            $this->refuse($what, null);
        }
    }

    /**
     * One message for every refusal. "Not yours" and "does not exist" read the
     * same, so walking account ids tells an attacker nothing - and the attempt
     * is on the record either way.
     */
    private function refuse(string $what, ?string $target): never
    {
        AuthEventLogger::failure(
            AuthEventType::ACCESS_DENIED,
            Auth::id(),
            'Refused attempt to ' . $what . ($target !== null ? ' of another account.' : '.')
        );

        throw new AuthorizationException('You do not have access to that.');
    }

    /** Built on first use, not in the constructor - the lazy half of the pattern. */
    private function real(): AccountService
    {
        return $this->service ??= $this->injected ?? new AccountService();
    }
}
