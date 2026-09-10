<?php
// Profile viewing, editing, password change and deactivation. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\AccountServiceInterface;
use App\Domain\AccountServiceProxy;
use App\Model\FacilityOwner;
use App\Model\FriendConnectionMapper;
use App\Model\User;
use App\Security\Auth;
use App\Security\PasswordPolicy;
use App\Security\Validator;
use App\Service\ParticipationHistory;
use App\Service\RemoteServices;
use App\Service\ReviewService;
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
    private AccountServiceInterface $accounts;
    private RemoteServices $services;
    private ReviewService $reviews;

    public function __construct(
        ?AccountServiceInterface $accounts = null,
        ?RemoteServices $services = null,
        ?ReviewService $reviews = null
    ) {
        $this->accounts = $accounts ?? new AccountServiceProxy();
        $this->services = $services ?? new RemoteServices();
        $this->reviews = $reviews ?? new ReviewService();
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

        $this->view('profile-show', [
            'title'        => $account->getUsername(),
            'account'      => $account,
            'isSelf'       => $account->getBaseUserId() === $current->getBaseUserId(),
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

        try {
            $this->accounts->updateProfile($baseUserId, $this->profileRules($_POST, $account));

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

    public function showOther(): void
    {
        $current = Auth::requireLogin();
        $userId = $this->queryId() ?? $current->getBaseUserId();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $isSelf = $userId === $current->getBaseUserId();
        $account = $this->accounts->viewPublicProfile($userId, $current->getBaseUserId());
        $connectionState = $isSelf
            ? null
            : (new FriendConnectionMapper())->connectionState($current->getBaseUserId(), $userId);
        $history = [];

        // Only players join events, so only they have a history to show.
        if ($account instanceof User) {
            $history = (new ParticipationHistory())->forUser($account->getBaseUserId());
        }

        $reviewData = $this->reviews->page('user', $userId, $page);

        $this->view('profile-public', [
            'title'        => $account->getUsername(),
            'account'      => $account,
            'isSelf'       => $isSelf,
            'connectionState' => $connectionState,
            'history'      => $history,
            'lastLoginAt'  => $_SESSION['_last_login_at'] ?? null,
            'recentEvents' => $isSelf ? $this->accounts->securityHistory($account->getBaseUserId(), 8) : [],
            'reviews'       => $reviewData['reviews'],
            'reviewAuthors' => $reviewData['reviewAuthors'],
            'reviewPage'    => $page,
            'reviewPages'   => $reviewData['reviewPages'],
        ]);
    }

    public function sendFriendRequest(): void
    {
        $this->requirePostWithCsrf();

        $current = Auth::requireLogin();
        $targetId = is_string($_POST['userId'] ?? null) ? $_POST['userId'] : '';

        if ($targetId === '' || $targetId === $current->getBaseUserId()) {
            $this->flash('error', 'That friend request is not valid.');
            $this->redirect(url('profile', 'showOther', ['id' => $targetId]));
        }

        $target = $this->accounts->viewPublicProfile($targetId, $current->getBaseUserId());
        $created = (new FriendConnectionMapper())->sendRequest($current, $target);

        $this->flash(
            $created ? 'success' : 'error',
            $created ? 'Friend request sent.' : 'A friend connection already exists.'
        );
        $this->redirect(url('profile', 'showOther', ['id' => $targetId]));
    }

    public function discover(): void
    {
        $current = Auth::requireLogin();
        $query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $users = $this->accounts->searchUsers($query, $current->getBaseUserId());

        $friendIds = [];
        foreach ((new FriendConnectionMapper())->findFriends($current) as $connection) {
            $friend = $connection->getRequester()->getBaseUserId() === $current->getBaseUserId()
                ? $connection->getAddressee()
                : $connection->getRequester();
            $friendIds[$friend->getBaseUserId()] = true;
        }

        $this->view('profile-discover', [
            'title'     => 'Discover users',
            'query'     => $query,
            'users'     => $users,
            'friendIds' => $friendIds,
        ]);
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
                ->text('favoriteSport', 'Favourite sport', 2, 50)
                ->text('location', 'Location', 2, 255)
                ->date('birthDate', 'Date of birth')
                ->imageUrl('profilePicURL', 'Profile picture address')
                ->latitude('latitude', 'Latitude')
                ->longitude('longitude', 'Longitude');
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
        foreach (['favoriteSport', 'location', 'profilePicURL', 'birthDate', 'latitude', 'longitude'] as $optional) {
            if (array_key_exists($optional, $input) && !array_key_exists($optional, $validated)) {
                $validated[$optional] = null;
            }
        }

        return $validated;
    }
}
