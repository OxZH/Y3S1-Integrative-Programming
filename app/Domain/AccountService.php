<?php
// The real subject: account and credential operations. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Domain;

use App\AccountStatus;
use App\AuthEventType;
use App\Model\Account;
use App\Model\AccountMapper;
use App\Model\Admin;
use App\Model\AuthEvent;
use App\Model\AuthEventMapper;
use App\Model\FacilityOwner;
use App\Model\PasswordResetMapper;
use App\Model\PasswordResetToken;
use App\Model\User;
use App\NotFoundException;
use App\Security\AuthEventLogger;
use App\Security\PasswordPolicy;
use App\UserType;
use App\ValidationException;
use DateTimeImmutable;

/**
 * The RealSubject in the Proxy pattern: it does the work and assumes the caller
 * is already allowed to ask. Nothing here reads the session, and that is
 * deliberate - "who is signed in" is the proxy's business, so this class stays
 * testable and the two concerns do not tangle.
 *
 * It is not constructed by controllers. AccountServiceProxy owns it.
 */
final class AccountService implements AccountServiceInterface
{
    public function __construct(
        private AccountMapper $accounts = new AccountMapper(),
        private PasswordResetMapper $resets = new PasswordResetMapper(),
        private AuthEventMapper $events = new AuthEventMapper()
    ) {
    }

    // ------------------------------------------------------------ registration

    /** @param array<string,mixed> $validated */
    public function register(array $validated): Account
    {
        $email    = (string) $validated['email'];
        $username = (string) $validated['username'];
        $password = (string) $validated['password'];

        $errors = [];

        // Checked here as well as by the UNIQUE keys. The keys are what actually
        // guarantee it; this is what turns a database error into a readable one.
        if ($this->accounts->emailTaken($email)) {
            $errors['email'] = 'An account with that email already exists.';
        }

        if ($this->accounts->usernameTaken($username)) {
            $errors['username'] = 'That username is taken.';
        }

        if ($password !== (string) ($validated['passwordConfirm'] ?? '')) {
            $errors['passwordConfirm'] = 'The two passwords do not match.';
        }

        $weak = PasswordPolicy::reject($password, [$email, $username]);

        if ($weak !== null) {
            $errors['password'] = $weak;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // The form offers only USER and FACILITY_OWNER. Re-checked here so that
        // posting userType=ADMIN cannot mint staff, whatever the form said.
        $type = $validated['userType'] instanceof UserType ? $validated['userType'] : UserType::USER;

        if (!in_array($type, UserType::registrable(), true)) {
            $type = UserType::USER;
        }

        $account = $this->newAccountOfType($type, $validated, $email, $username);

        $this->accounts->insertWithPassword($account, PasswordPolicy::hash($password));

        AuthEventLogger::success(AuthEventType::REGISTERED, $account->getBaseUserId(), $type->label() . ' account created.');

        return $account;
    }

    // ---------------------------------------------------------- authentication

    /**
     * Section 5.2 threat 1 lives in this method.
     *
     * Every rejection returns the SAME message, so the form cannot be used to
     * discover which addresses are registered. When the email matches nothing
     * the method still spends the time a hash comparison would, so the response
     * time does not answer the question either.
     */
    public function authenticate(string $email, string $plainPassword): Account
    {
        $generic = new ValidationException(['password' => 'Email or password is incorrect.']);

        $credentials = $this->accounts->credentialsForEmail($email);

        if ($credentials === null) {
            PasswordPolicy::burnTime();
            AuthEventLogger::failedLoginForUnknownEmail($email);

            throw $generic;
        }

        $baseUserId = $credentials['baseUserId'];
        $locked     = PasswordPolicy::isLocked($credentials['lockedUntil']);
        $correct    = PasswordPolicy::verify($plainPassword, $credentials['password']);

        if (!$correct) {
            $this->registerFailure($baseUserId, $locked);

            throw $generic;
        }

        // The password was right, so telling this caller the account is locked
        // gives away nothing they did not already know.
        if ($locked) {
            AuthEventLogger::failure(AuthEventType::LOGIN_FAILED, $baseUserId, 'Correct password while locked out.');

            throw new ValidationException([
                'password' => sprintf(
                    'This account is temporarily locked after %d failed attempts. Try again in a few minutes.',
                    PasswordPolicy::MAX_ATTEMPTS
                ),
            ]);
        }

        if ($credentials['accountStatus'] !== AccountStatus::ACTIVE->value) {
            AuthEventLogger::failure(AuthEventType::LOGIN_FAILED, $baseUserId, 'Account is ' . $credentials['accountStatus'] . '.');

            throw new ValidationException([
                'password' => 'This account is not active. Please contact support.',
            ]);
        }

        $account = $this->accounts->findAccount($baseUserId);

        if ($account === null) {
            throw $generic;
        }

        // Stored settings may be weaker than today's. The plaintext is in hand
        // exactly once, at a successful login, so this is the only moment the
        // hash can be quietly upgraded.
        if (PasswordPolicy::needsRehash($credentials['password'])) {
            $this->accounts->storePasswordHash($baseUserId, PasswordPolicy::hash($plainPassword));
        }

        $this->accounts->clearLoginFailures($baseUserId);

        AuthEventLogger::success(AuthEventType::LOGIN_SUCCESS, $baseUserId);

        return $account;
    }

    // ----------------------------------------------------------------- profile

    public function viewProfile(string $baseUserId): Account
    {
        $account = $this->accounts->findAccount($baseUserId);

        if ($account === null) {
            throw new NotFoundException('That account could not be found.');
        }

        return $account;
    }

    /** @param array<string,mixed> $validated */
    public function updateProfile(string $baseUserId, array $validated): Account
    {
        $account = $this->viewProfile($baseUserId);
        $errors  = [];

        $email    = (string) ($validated['email'] ?? $account->getEmail());
        $username = (string) ($validated['username'] ?? $account->getUsername());

        if ($email !== $account->getEmail() && $this->accounts->emailTaken($email, $baseUserId)) {
            $errors['email'] = 'An account with that email already exists.';
        }

        if ($username !== $account->getUsername() && $this->accounts->usernameTaken($username, $baseUserId)) {
            $errors['username'] = 'That username is taken.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $account->setEmail($email);
        $account->setUsername($username);
        $account->setContactNumber((string) ($validated['contactNumber'] ?? $account->getContactNumber()));

        $this->applyRoleFields($account, $validated);

        $this->accounts->update($account);

        AuthEventLogger::success(AuthEventType::PROFILE_UPDATED, $baseUserId);

        return $account;
    }

    // ---------------------------------------------------------------- password

    /**
     * The current password is required even though the caller is already signed
     * in. A session someone walked away from should not be enough to take the
     * account permanently.
     */
    public function changePassword(string $baseUserId, string $currentPassword, string $newPassword): void
    {
        $account = $this->viewProfile($baseUserId);
        $stored  = $this->accounts->passwordHashFor($baseUserId);

        if ($stored === null || !PasswordPolicy::verify($currentPassword, $stored)) {
            AuthEventLogger::failure(AuthEventType::PASSWORD_CHANGED, $baseUserId, 'Current password did not match.');

            throw new ValidationException(['currentPassword' => 'That is not your current password.']);
        }

        if (PasswordPolicy::verify($newPassword, $stored)) {
            throw new ValidationException(['newPassword' => 'The new password must be different from the current one.']);
        }

        $weak = PasswordPolicy::reject($newPassword, [$account->getEmail(), $account->getUsername()]);

        if ($weak !== null) {
            throw new ValidationException(['newPassword' => $weak]);
        }

        $this->accounts->storePasswordHash($baseUserId, PasswordPolicy::hash($newPassword));

        // Any reset link outstanding for this account dies now.
        $this->resets->invalidateAllFor($baseUserId);

        AuthEventLogger::success(AuthEventType::PASSWORD_CHANGED, $baseUserId);
    }

    /**
     * Returns nothing and reveals nothing. Whether or not the address matched an
     * account, the caller sees the same screen - otherwise this form becomes a
     * way to test which addresses are registered.
     */
    public function requestPasswordReset(string $email): void
    {
        $account = $this->accounts->findByEmail($email);

        if ($account === null) {
            AuthEventLogger::failedLoginForUnknownEmail($email);

            return;
        }

        $baseUserId = $account->getBaseUserId();

        // Rate limited, so this cannot be used to bury someone in reset mail.
        $recent = $this->resets->countRequestedSince($baseUserId, new DateTimeImmutable('-1 hour'));

        if ($recent >= PasswordPolicy::RESET_REQUESTS_PER_HOUR) {
            AuthEventLogger::failure(AuthEventType::PASSWORD_RESET_REQUESTED, $baseUserId, 'Rate limit reached.');

            return;
        }

        $token = PasswordPolicy::newResetToken();

        $this->resets->insert(new PasswordResetToken(
            uuid(),
            $baseUserId,
            PasswordPolicy::hashResetToken($token),
            PasswordPolicy::resetExpiry(),
            null,
            null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ));

        AuthEventLogger::success(AuthEventType::PASSWORD_RESET_REQUESTED, $baseUserId);

        // There is no mail server in this project, so the link is handed to the
        // delivery class, which writes it where the demo can pick it up. In
        // production this is the one place that changes: the link goes to the
        // address already on the account and nowhere else.
        ResetLinkDelivery::send($account, $token);
    }

    public function resetPassword(string $rawToken, string $newPassword): void
    {
        $invalid = new ValidationException([
            'password' => 'That reset link is invalid or has expired. Please request a new one.',
        ]);

        if ($rawToken === '') {
            throw $invalid;
        }

        $ticket = $this->resets->findByTokenHash(PasswordPolicy::hashResetToken($rawToken));

        if ($ticket === null || !$ticket->isRedeemable()) {
            throw $invalid;
        }

        $account = $this->accounts->findAccount($ticket->getBaseUserId());

        if ($account === null) {
            throw $invalid;
        }

        $weak = PasswordPolicy::reject($newPassword, [$account->getEmail(), $account->getUsername()]);

        if ($weak !== null) {
            throw new ValidationException(['password' => $weak]);
        }

        // Claim the ticket before changing anything. If two requests arrive with
        // the same link, only the one that flips usedAt from NULL proceeds.
        if (!$this->resets->markUsed($ticket->getPasswordResetId())) {
            throw $invalid;
        }

        $this->accounts->storePasswordHash($account->getBaseUserId(), PasswordPolicy::hash($newPassword));
        $this->resets->invalidateAllFor($account->getBaseUserId());

        AuthEventLogger::success(AuthEventType::PASSWORD_RESET_COMPLETED, $account->getBaseUserId());
    }

    // ------------------------------------------------------------------ status

    public function deactivate(string $baseUserId, string $currentPassword): void
    {
        $stored = $this->accounts->passwordHashFor($baseUserId);

        if ($stored === null || !PasswordPolicy::verify($currentPassword, $stored)) {
            AuthEventLogger::failure(AuthEventType::ACCOUNT_DEACTIVATED, $baseUserId, 'Password confirmation failed.');

            throw new ValidationException(['currentPassword' => 'That is not your current password.']);
        }

        $this->accounts->setStatus($baseUserId, AccountStatus::DEACTIVATED);
        $this->resets->invalidateAllFor($baseUserId);

        AuthEventLogger::success(AuthEventType::ACCOUNT_DEACTIVATED, $baseUserId);
    }

    public function reactivate(string $baseUserId): void
    {
        $this->accounts->setStatus($baseUserId, AccountStatus::ACTIVE);

        AuthEventLogger::success(AuthEventType::ACCOUNT_REACTIVATED, $baseUserId);
    }

    /** @return Account[] */
    public function listAccounts(): array
    {
        return $this->accounts->findAll();
    }

    /** @return AuthEvent[] */
    public function securityHistory(string $baseUserId, int $limit = 20): array
    {
        return $this->events->recentForUser($baseUserId, $limit);
    }

    // ----------------------------------------------------------------- private

    /**
     * One wrong password. Counts it, and locks the account once the run of
     * consecutive failures reaches the limit.
     *
     * The counter is only reset by a successful sign-in, so five wrong guesses
     * spread over an hour lock the account just as five in a row do - an
     * attacker cannot stay under the limit by slowing down.
     */
    private function registerFailure(string $baseUserId, bool $alreadyLocked): void
    {
        $attempts = $this->accounts->recordFailedLogin($baseUserId);

        AuthEventLogger::failure(
            AuthEventType::LOGIN_FAILED,
            $baseUserId,
            sprintf('Wrong password (attempt %d of %d).', $attempts, PasswordPolicy::MAX_ATTEMPTS)
        );

        if ($alreadyLocked || $attempts < PasswordPolicy::MAX_ATTEMPTS) {
            return;
        }

        $until = PasswordPolicy::lockoutEnds();

        $this->accounts->lockUntil($baseUserId, $until);

        AuthEventLogger::failure(
            AuthEventType::ACCOUNT_LOCKED,
            $baseUserId,
            sprintf('Locked until %s after %d failed attempts.', $until->format('H:i'), $attempts)
        );
    }

    /** @param array<string,mixed> $validated */
    private function newAccountOfType(UserType $type, array $validated, string $email, string $username): Account
    {
        $contact = (string) ($validated['contactNumber'] ?? '');
        $id      = uuid();

        if ($type === UserType::FACILITY_OWNER) {
            return new FacilityOwner(
                $id,
                $email,
                $username,
                $contact,
                bankName: (string) ($validated['bankName'] ?? ''),
                bankAccountNum: (string) ($validated['bankAccountNum'] ?? ''),
                businessRegNum: (string) ($validated['businessRegNum'] ?? '')
            );
        }

        $user = new User(
            $id,
            $email,
            $username,
            $contact,
            favoriteSport: $this->nullable($validated['favoriteSport'] ?? null),
            location: $this->nullable($validated['location'] ?? null),
            birthDate: ($validated['birthDate'] ?? null) instanceof DateTimeImmutable ? $validated['birthDate'] : null
        );

        // Turn the address into coordinates, so the discovery module can sort
        // events by distance and recommend nearby ones.
        $this->applyGeocodedCoordinates($user);

        return $user;
    }

    /**
     * Best effort address lookup. A player types where they live, the discovery
     * module needs that as a point on a map; AddressGeocoder asks OpenStreetMap
     * and falls back to a fixed city table.
     *
     * Failure is silent on purpose: an account must still be created, and a
     * profile must still save, when the lookup finds nothing or OpenStreetMap
     * is unreachable. The player can always type the coordinates on the profile
     * form instead.
     */
    private function applyGeocodedCoordinates(User $user): void
    {
        $location = $user->getLocation();

        if ($location === null || trim($location) === '') {
            return;
        }

        $coordinates = (new AddressGeocoder())->locate($location);

        if ($coordinates !== null) {
            $user->setCoordinates($coordinates[0], $coordinates[1]);
        }
    }

    /** @param array<string,mixed> $validated */
    private function applyRoleFields(Account $account, array $validated): void
    {
        // Only keys the caller actually sent are applied. A field the form did
        // not include means "leave it alone", never "clear it" - otherwise a
        // partial form silently wipes whatever it happened not to render.
        if ($account instanceof User) {
            if (array_key_exists('favoriteSport', $validated)) {
                $account->setFavoriteSport($this->nullable($validated['favoriteSport']));
            }

            if (array_key_exists('location', $validated)) {
                $account->setLocation($this->nullable($validated['location']));

                // A new address means the old coordinates are stale. Skipped when
                // the form sent coordinates of its own, handled below.
                if (!array_key_exists('latitude', $validated) && !array_key_exists('longitude', $validated)) {
                    $this->applyGeocodedCoordinates($account);
                }
            }

            if (array_key_exists('profilePicURL', $validated)) {
                $account->setProfilePicURL($this->nullable($validated['profilePicURL']));
            }

            if (array_key_exists('birthDate', $validated)) {
                $account->setBirthDate($validated['birthDate'] instanceof DateTimeImmutable ? $validated['birthDate'] : null);
            }

            if (array_key_exists('latitude', $validated) || array_key_exists('longitude', $validated)) {
                $latitude  = $validated['latitude']  ?? null;
                $longitude = $validated['longitude'] ?? null;

                $account->setCoordinates(
                    is_numeric($latitude) ? (float) $latitude : null,
                    is_numeric($longitude) ? (float) $longitude : null
                );
            }

            return;
        }

        if ($account instanceof FacilityOwner) {
            $account->setBankName((string) ($validated['bankName'] ?? $account->getBankName()));
            $account->setBusinessRegNum((string) ($validated['businessRegNum'] ?? $account->getBusinessRegNum()));

            // Blank means "leave it alone". The form shows the number masked, so
            // submitting the masked value back must not overwrite the real one.
            $bankAccount = trim((string) ($validated['bankAccountNum'] ?? ''));

            if ($bankAccount !== '') {
                $account->setBankAccountNum($bankAccount);
            }

            return;
        }

        if ($account instanceof Admin && isset($validated['adminId'])) {
            $account->setAdminId((string) $validated['adminId']);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
