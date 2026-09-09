<?php
// Temporary account picker for testing this module. Author: Goh Jian Yu

namespace App\Controller;

use App\Core\Controller;
use App\Model\AccountMapper;
use App\Security\Auth;

// Stand-in until the User Authentication module is wired in. It checks no
// password - it only selects a seeded account so this module's ownership checks
// and host-only actions can be exercised. Delete it then.
final class LoginController extends Controller
{
    public function index(): void
    {
        $accounts = new AccountMapper();

        $this->view('login', [
            'title'  => 'Choose a demo account',
            'owners' => $accounts->findByType('FACILITY_OWNER'),
            'users'  => $accounts->findByType('USER'),
        ]);
    }

    public function login(): void
    {
        $this->requirePostWithCsrf();

        $baseUserId = (string) ($_POST['baseUserId'] ?? '');
        $account    = (new AccountMapper())->findAccount($baseUserId);

        if ($account === null) {
            $this->flash('error', 'That account does not exist.');
            $this->redirect(url('login'));
        }

        Auth::login($baseUserId);

        $this->flash('success', 'Signed in as ' . $account->getUsername() . '.');
        $this->redirect(url('event'));
    }

    public function logout(): void
    {
        $this->requirePostWithCsrf();

        Auth::logout();

        $this->flash('success', 'Signed out.');
        $this->redirect(url('event'));
    }
}
