<?php
// Registration, sign-in, sign-out and password recovery. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Controller;

use App\AuthEventType;
use App\Core\Controller;
use App\Domain\AccountServiceInterface;
use App\Domain\AccountServiceProxy;
use App\Domain\ResetLinkDelivery;
use App\Model\AccountMapper;
use App\Security\Auth;
use App\Security\AuthEventLogger;
use App\Security\PasswordPolicy;
use App\Security\Validator;
use App\UserType;
use App\ValidationException;

/**
 * The doors into the system. Every action here is reachable without a session,
 * which is exactly why the rules in PasswordPolicy and the audit trail matter
 * on this file more than anywhere else.
 *
 * The controller holds an AccountServiceInterface, and what it is actually
 * given is the proxy. It never sees AccountService.
 */
final class AuthController extends Controller
{
    private AccountServiceInterface $accounts;

    public function __construct(?AccountServiceInterface $accounts = null)
    {
        $this->accounts = $accounts ?? new AccountServiceProxy();
    }

    // ------------------------------------------------------------------ login

    public function index(): void
    {
        if (Auth::check()) {
            $this->redirect(url('profile'));
        }

        $this->view('auth-login', [
            'title'  => 'Sign in',
            'input'  => [],
            'errors' => [],
        ]);
    }

    public function login(): void
    {
        $this->requirePostWithCsrf();

        $email    = is_string($_POST['email'] ?? null) ? trim(mb_strtolower($_POST['email'])) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        try {
            $account = $this->accounts->authenticate($email, $password);
        } catch (ValidationException $e) {
            // The email is echoed back so the form is not annoying to retry.
            // The password never is.
            $this->view('auth-login', [
                'title'  => 'Sign in',
                'input'  => ['email' => $email],
                'errors' => $e->getErrors(),
            ]);

            return;
        }

        // Read before Auth::login() overwrites it: "last time you signed in".
        $previous = (new AccountMapper())->previousLoginAt($account->getBaseUserId());

        // Issues a new session id and a new CSRF token, so a session id planted
        // before sign-in is worthless afterwards.
        Auth::login($account->getBaseUserId());

        $_SESSION['_last_login_at'] = $previous;

        $this->flash('success', 'Signed in as ' . $account->getUsername() . '.');
        $this->redirect(url('profile'));
    }

    public function logout(): void
    {
        $this->requirePostWithCsrf();

        $id = Auth::id();

        Auth::logout();

        if ($id !== null) {
            AuthEventLogger::success(AuthEventType::LOGOUT, $id);
        }

        $this->flash('success', 'Signed out.');
        $this->redirect(url('auth'));
    }

    // ----------------------------------------------------------- registration

    public function register(): void
    {
        if (Auth::check()) {
            $this->redirect(url('profile'));
        }

        $this->view('auth-register', [
            'title'  => 'Create an account',
            'input'  => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        $this->requirePostWithCsrf();

        try {
            $validated = $this->registrationRules($_POST);
            $account   = $this->accounts->register($validated);

            Auth::login($account->getBaseUserId());

            $this->flash('success', 'Welcome, ' . $account->getUsername() . '. Your account is ready.');
            $this->redirect(url('profile'));
        } catch (ValidationException $e) {
            $this->view('auth-register', [
                'title'  => 'Create an account',
                'input'  => $_POST,
                'errors' => $e->getErrors(),
            ]);
        }
    }

    // -------------------------------------------------------------- recovery

    public function forgot(): void
    {
        $this->view('auth-forgot', [
            'title'  => 'Reset your password',
            'input'  => [],
            'errors' => [],
            'sent'   => false,
            'demoLink' => null,
        ]);
    }

    /**
     * Always renders the same confirmation. Whether the address matched an
     * account is not something this form is willing to answer.
     */
    public function sendReset(): void
    {
        $this->requirePostWithCsrf();

        $email = is_string($_POST['email'] ?? null) ? trim(mb_strtolower($_POST['email'])) : '';

        if ($email !== '') {
            $this->accounts->requestPasswordReset($email);
        }

        $this->view('auth-forgot', [
            'title'    => 'Reset your password',
            'input'    => [],
            'errors'   => [],
            'sent'     => true,
            // Debug builds only - stands in for opening the email.
            'demoLink' => config('app.debug') ? ResetLinkDelivery::takeDemoLink() : null,
        ]);
    }

    public function resetForm(): void
    {
        $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';

        $this->view('auth-reset', [
            'title'  => 'Choose a new password',
            'token'  => $token,
            'errors' => [],
        ]);
    }

    public function reset(): void
    {
        $this->requirePostWithCsrf();

        $token    = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm  = is_string($_POST['passwordConfirm'] ?? null) ? $_POST['passwordConfirm'] : '';

        if ($password !== $confirm) {
            $this->view('auth-reset', [
                'title'  => 'Choose a new password',
                'token'  => $token,
                'errors' => ['passwordConfirm' => 'The two passwords do not match.'],
            ]);

            return;
        }

        try {
            $this->accounts->resetPassword($token, $password);
        } catch (ValidationException $e) {
            $this->view('auth-reset', [
                'title'  => 'Choose a new password',
                'token'  => $token,
                'errors' => $e->getErrors(),
            ]);

            return;
        }

        // Not signed in automatically: whoever redeemed the link has proved they
        // read the inbox, not that they are the account holder.
        $this->flash('success', 'Your password has been changed. Please sign in with it.');
        $this->redirect(url('auth'));
    }

    // --------------------------------------------------------------- private

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function registrationRules(array $input): array
    {
        $validator = (new Validator($input))
            ->required('email', 'Email')->email('email', 'Email')
            ->required('username', 'Username')->text('username', 'Username', 3, 50)
            ->required('contactNumber', 'Contact number')->phone('contactNumber', 'Contact number')
            ->password('password', 'Password')
            ->enum('userType', 'Account type', UserType::class);

        // Only the fields that belong to the chosen role are collected. A player
        // posting bank details, or an owner posting a birth date, gets them
        // dropped rather than stored on the wrong table.
        if (($input['userType'] ?? '') === UserType::FACILITY_OWNER->value) {
            $validator
                ->required('bankName', 'Bank name')->text('bankName', 'Bank name', 2, 100)
                ->required('bankAccountNum', 'Bank account number')->text('bankAccountNum', 'Bank account number', 5, 50)
                ->required('businessRegNum', 'Business registration number')
                ->text('businessRegNum', 'Business registration number', 4, 50);
        } else {
            $validator
                ->text('favoriteSport', 'Favourite sport', 2, 50)
                ->text('location', 'Location', 2, 255)
                ->date('birthDate', 'Date of birth');
        }

        $validated = $validator->validate();

        // Not run through the Validator: confirmation is a comparison, not a
        // format, and AccountService is where the two are compared.
        $validated['passwordConfirm'] = is_string($input['passwordConfirm'] ?? null) ? $input['passwordConfirm'] : '';
        $validated['userType']      ??= UserType::USER;

        return $validated;
    }
}
