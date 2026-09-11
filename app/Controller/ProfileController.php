<?php
// Profile viewing, editing, password change and deactivation. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\AccountServiceInterface;
use App\Domain\AccountServiceProxy;
use App\Domain\ProfileImage;
use App\Model\FacilityOwner;
use App\Model\User;
use App\Security\Auth;
use App\Security\PasswordPolicy;
use App\Security\Validator;
use App\Service\ParticipationHistory;
use App\Sport;
use App\ValidationException;

/**
 * Everything a signed-in person does to their own account.
 *
 * There is no ownership check anywhere in this file. That is not an oversight:
 * every call goes through AccountServiceProxy, which refuses anything that is
 * not the caller's own account (or an administrator's). Putting the check here
 * as well would mean two places to keep in step.
 */
final class ProfileController extends Controller
{
    /** Kept in step with AuthController: the same rule on both forms. */
    private const MINIMUM_AGE_YEARS = 3;

    private AccountServiceInterface $accounts;

    public function __construct(?AccountServiceInterface $accounts = null)
    {
        $this->accounts = $accounts ?? new AccountServiceProxy();
    }

    public function index(): void
    {
        $current = Auth::requireLogin();

        // An id in the query string is honoured, and the proxy decides whether
        // it is allowed. Anyone else's id comes back refused, not rendered.
        $baseUserId = $this->queryId() ?? $current->getBaseUserId();
        $account    = $this->accounts->viewProfile($baseUserId);

        $history = [];

        // Only players join events, so only they have a history to show.
        if ($account instanceof User) {
            $history = (new ParticipationHistory())->forUser($account->getBaseUserId());
        }

        $isSelf = $account->getBaseUserId() === $current->getBaseUserId();

        $this->view('profile-show', [
            'title'        => $account->getUsername(),
            'account'      => $account,
            'isSelf'       => $isSelf,
            'history'      => $history,
            'lastLoginAt'  => $_SESSION['_last_login_at'] ?? null,
            'recentEvents' => $this->accounts->securityHistory($account->getBaseUserId(), 8),
        ]);
    }

    public function edit(): void
    {
        $current    = Auth::requireLogin();
        $baseUserId = $this->queryId() ?? $current->getBaseUserId();

        $this->view('profile-form', [
            'title'   => 'Edit profile',
            'account' => $this->accounts->viewProfile($baseUserId),
            'input'   => [],
            'errors'  => [],
        ]);
    }

    public function update(): void
    {
        $this->requirePostWithCsrf();

        $current    = Auth::requireLogin();
        $baseUserId = is_string($_POST['baseUserId'] ?? null) && $_POST['baseUserId'] !== ''
            ? $_POST['baseUserId']
            : $current->getBaseUserId();

        // Read through the proxy first, so an id that is not the caller's is
        // refused before any input is looked at.
        $account = $this->accounts->viewProfile($baseUserId);

        $previousPicture = $account instanceof User ? $account->getProfilePicURL() : null;

        try {
            $validated = $this->profileRules($_POST, $account);

            if ($account instanceof User) {
                // Saved to disk only after the proxy has agreed this is the
                // caller's own account, so a refused request writes no file.
                // The id comes from the resolved account, never from the form.
                $uploaded = ProfileImage::save($_FILES['profilePicture'] ?? null, $baseUserId);

                if ($uploaded !== null) {
                    $validated['profilePicURL'] = $uploaded;
                } elseif (($_POST['removeProfilePicture'] ?? '') === '1') {
                    $validated['profilePicURL'] = null;
                }
                // Neither: the key stays absent and the stored picture is kept.
            }

            $this->accounts->updateProfile($baseUserId, $validated);

            // Only once the row is saved. Deleting first would lose the old
            // picture if the update then failed on a duplicate email.
            // array_key_exists, not ??. Removing a picture sets the key to
            // NULL on purpose, and ?? treats an explicit null as absent - so
            // the old file would be left behind on disk after every removal.
            $newPicture = array_key_exists('profilePicURL', $validated)
                ? $validated['profilePicURL']
                : $previousPicture;

            if ($previousPicture !== null && $previousPicture !== $newPicture) {
                ProfileImage::remove($previousPicture);
            }

            $this->flash('success', 'Your profile has been updated.');
            $this->redirect(url('profile'));
        } catch (ValidationException $e) {
            $this->view('profile-form', [
                'title'   => 'Edit profile',
                'account' => $account,
                'input'   => $_POST,
                'errors'  => $e->getErrors(),
            ]);
        }
    }

    public function security(): void
    {
        $current = Auth::requireLogin();

        $this->view('profile-security', [
            'title'   => 'Password and security',
            'account' => $current,
            'events'  => $this->accounts->securityHistory($current->getBaseUserId(), 25),
            'errors'  => [],
        ]);
    }

    public function changePassword(): void
    {
        $this->requirePostWithCsrf();

        $current = Auth::requireLogin();

        $currentPassword = is_string($_POST['currentPassword'] ?? null) ? $_POST['currentPassword'] : '';
        $newPassword     = is_string($_POST['newPassword'] ?? null) ? $_POST['newPassword'] : '';
        $confirm         = is_string($_POST['newPasswordConfirm'] ?? null) ? $_POST['newPasswordConfirm'] : '';

        $errors = [];

        if ($newPassword !== $confirm) {
            $errors['newPasswordConfirm'] = 'The two passwords do not match.';
        }

        if ($errors === []) {
            try {
                $this->accounts->changePassword($current->getBaseUserId(), $currentPassword, $newPassword);

                $this->flash('success', 'Your password has been changed.');
                $this->redirect(url('profile', 'security'));
            } catch (ValidationException $e) {
                $errors = $e->getErrors();
            }
        }

        $this->view('profile-security', [
            'title'   => 'Password and security',
            'account' => $current,
            'events'  => $this->accounts->securityHistory($current->getBaseUserId(), 25),
            'errors'  => $errors,
        ]);
    }

    public function deactivate(): void
    {
        $this->requirePostWithCsrf();

        $current  = Auth::requireLogin();
        $password = is_string($_POST['currentPassword'] ?? null) ? $_POST['currentPassword'] : '';

        try {
            $this->accounts->deactivate($current->getBaseUserId(), $password);
        } catch (ValidationException $e) {
            $this->view('profile-security', [
                'title'   => 'Password and security',
                'account' => $current,
                'events'  => $this->accounts->securityHistory($current->getBaseUserId(), 25),
                'errors'  => $e->getErrors(),
            ]);

            return;
        }

        // The session goes with the account. Auth::user() would drop it on the
        // next request anyway, since it re-checks the status every time.
        Auth::logout();

        $this->flash('success', 'Your account has been deactivated. Contact support if you want it back.');
        $this->redirect(url('auth'));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function profileRules(array $input, object $account): array
    {
        $validator = (new Validator($input))
            ->required('email', 'Email')->email('email', 'Email')
            ->required('username', 'Username')->text('username', 'Username', 3, 50)
            ->required('contactNumber', 'Contact number')->phone('contactNumber', 'Contact number');

        if ($account instanceof User) {
            $validator
                ->inListMultiple('favoriteSports', 'Favourite sports', Sport::values())
                ->text('location', 'Location', 2, 255)
                ->date('birthDate', 'Date of birth')
                ->minimumAge('birthDate', 'Date of birth', self::MINIMUM_AGE_YEARS);

            // No profilePicURL rule: the picture is an uploaded file now, not
            // a typed address, so the path is produced by ProfileImage rather
            // than accepted from the request.

            // No latitude/longitude rules. The form does not offer them and the
            // service ignores them, so there is nothing here to validate.
        }

        if ($account instanceof FacilityOwner) {
            $validator
                ->required('bankName', 'Bank name')->text('bankName', 'Bank name', 2, 100)
                ->required('businessRegNum', 'Business registration number')
                ->text('businessRegNum', 'Business registration number', 4, 50)
                // Optional: blank leaves the stored number alone, so the masked
                // value shown on the form is never written back over the real one.
                ->text('bankAccountNum', 'Bank account number', 5, 50);
        }

        $validated = $validator->validate();

        // The Validator skips an optional field that arrived empty, so an
        // emptied box would otherwise look identical to a box the form never
        // rendered. Emptying one on purpose has to mean something, so a field
        // that WAS submitted and came back blank is passed on as an explicit
        // null; a field that was not submitted at all is left out entirely and
        // the service keeps the stored value.
        foreach (['favoriteSports', 'location', 'birthDate'] as $optional) {
            if (array_key_exists($optional, $input) && !array_key_exists($optional, $validated)) {
                $validated[$optional] = null;
            }
        }

        return $validated;
    }
}
