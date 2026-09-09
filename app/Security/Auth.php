<?php
// Who is signed in. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Security;

use App\AuthorizationException;
use App\Model\Account;
use App\Model\AccountMapper;

// Identity and role checks, shared by every module.
//
// Authentication proper - registration, password hashing, recovery - belongs to
// the User Authentication & Profile Management module. What lives here is the
// session side each module needs to answer "who is this".
//
// Record-level decisions do NOT belong here. "May this person edit this venue"
// is specific to whichever module owns that entity, so each module keeps its
// own file for that; see App\Security\EventFacilitySecurity for this one.
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    private static ?Account $cached = null;

    private function __construct()
    {
    }

    // Temporary: real login is the User Authentication module's job. Everything
    // below only ever asks Auth::id(), so none of it changes when it arrives.
    public static function login(string $baseUserId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // New session id on privilege change, against session fixation.
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = $baseUserId;
        self::$cached = null;

        Csrf::rotate();
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        self::$cached = null;

        Csrf::rotate();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function id(): ?string
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function user(): ?Account
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $id = self::id();

        if ($id === null) {
            return null;
        }

        $account = (new AccountMapper())->findAccount($id);

        // Checked every request, not just at login, so a deactivated account
        // loses access immediately.
        if ($account === null || !$account->isActive()) {
            self::logout();

            return null;
        }

        return self::$cached = $account;
    }

    public static function requireLogin(): Account
    {
        $account = self::user();

        if ($account === null) {
            throw new AuthorizationException('Please sign in to continue.');
        }

        return $account;
    }

    public static function requireFacilityOwner(): Account
    {
        $account = self::requireLogin();

        if (!$account->isFacilityOwner()) {
            throw new AuthorizationException('Only a facility owner can manage venues.');
        }

        return $account;
    }

    // FOR IVAN: this is the minimum needed to keep a facility owner off the
    // event pages, because Event.hostId is a foreign key to the User table and
    // an owner has no row there. Without it the insert reaches the database and
    // comes back as a foreign key error, which is not something a user should
    // ever be shown. Swap it for whatever your authorisation module ends up
    // using (roles, permissions, a policy check) - the event controller only
    // calls this one method.
    public static function requireMember(): Account
    {
        $account = self::requireLogin();

        if (!$account->isMember()) {
            throw new AuthorizationException(
                'Only a member account can create a game. Venue owners manage venues instead.'
            );
        }

        return $account;
    }
}
