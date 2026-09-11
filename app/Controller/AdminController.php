<?php
// Administrator account tools. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Controller;

use App\AuthEventType;
use App\Core\Controller;
use App\Domain\AccountServiceInterface;
use App\Domain\AccountServiceProxy;
use App\Model\AuthEventMapper;
use App\Security\Auth;
use App\Service\ReviewService;

/**
 * The administrator half of role-based access control.
 *
 * The account screens carry no role check of their own. That is deliberate:
 * AccountServiceProxy already refuses a non-administrator, and it also records
 * the refusal, so letting it answer keeps every rejected attempt on the audit
 * trail. A check here as well would throw first and the attempt would go
 * unrecorded.
 *
 * audit() is the exception - it reads the log directly rather than through the
 * proxy, so it has to ask for itself.
 */
final class AdminController extends Controller
{
    private AccountServiceInterface $accounts;
    private ReviewService $reviews;

    public function __construct(?AccountServiceInterface $accounts = null, ?ReviewService $reviews = null)
    {
        $this->accounts = $accounts ?? new AccountServiceProxy();
        $this->reviews = $reviews ?? new ReviewService();
    }

    public function accounts(): void
    {
        $this->view('admin-accounts', [
            'title'    => 'Accounts',
            'accounts' => $this->accounts->listAccounts(),
        ]);
    }

    public function reactivate(): void
    {
        $this->requirePostWithCsrf();

        $baseUserId = is_string($_POST['baseUserId'] ?? null) ? $_POST['baseUserId'] : '';

        if ($baseUserId !== '') {
            $this->accounts->reactivate($baseUserId);
            $this->flash('success', 'That account has been reactivated.');
        }

        $this->redirect(url('admin', 'accounts'));
    }

    /**
     * The system-wide audit trail. Restricted to administrators because it names
     * who signed in from where - the least-privilege line is that a player needs
     * their own history, not everybody's.
     */
    public function audit(): void
    {
        Auth::requireAdmin();

        $type = is_string($_GET['type'] ?? null) ? AuthEventType::tryFrom($_GET['type']) : null;
        $log  = new AuthEventMapper();

        $this->view('admin-audit', [
            'title'  => 'Security log',
            'events' => $type === null ? $log->recent(100) : $log->recentOfType($type, 100),
            'filter' => $type,
        ]);
    }

    public function spam(): void
    {
        Auth::requireAdmin();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $spamPage = $this->reviews->possibleSpamPage($page);
        $reviews = $spamPage['reviews'];
        $authors = [];
        foreach ($reviews as $review) {
            $authors[$review->getAuthorId()] = (new \App\Model\AccountMapper())->findAccount($review->getAuthorId());
        }

        $this->view('admin-spam', [
            'title'        => 'Possible spam reviews',
            'reviews'      => $reviews,
            'reviewAuthors' => $authors,
            'reviewPage'   => $page,
            'reviewPages'  => $spamPage['reviewPages'],
        ]);
    }
}
