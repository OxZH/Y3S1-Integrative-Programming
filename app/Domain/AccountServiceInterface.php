<?php
// The subject of the Proxy pattern. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Domain;

use App\Model\Account;
use App\Model\AuthEvent;

/**
 * Every account operation this module offers, named once.
 *
 * This is the Subject in the Proxy pattern. Two classes implement it:
 * AccountService does the work, AccountServiceProxy guards it. Because both
 * satisfy the same interface, a controller holding one cannot tell which it has
 * - which is the point: the guard cannot be bypassed by "just calling the real
 * one", because the real one is never handed out.
 */
interface AccountServiceInterface
{
    /** @param array<string,mixed> $validated */
    public function register(array $validated): Account;

    /** @throws \App\ValidationException when the credentials are wrong or the account is locked */
    public function authenticate(string $email, string $plainPassword): Account;

    public function viewProfile(string $baseUserId): Account;
    public function viewPublicProfile(string $requestedUserId, string $currentUserId): Account;

    /** @return \App\Model\User[] */
    public function searchUsers(string $query, ?string $excludeId = null): array;

    /** @param array<string,mixed> $validated */
    public function updateProfile(string $baseUserId, array $validated): Account;

    public function changePassword(string $baseUserId, string $currentPassword, string $newPassword): void;

    /** Always silent about whether the address matched an account. */
    public function requestPasswordReset(string $email): void;

    public function resetPassword(string $rawToken, string $newPassword): void;

    public function deactivate(string $baseUserId, string $currentPassword): void;

    public function reactivate(string $baseUserId): void;

    /** @return Account[] */
    public function listAccounts(): array;

    /** @return AuthEvent[] */
    public function securityHistory(string $baseUserId, int $limit = 20): array;
}
