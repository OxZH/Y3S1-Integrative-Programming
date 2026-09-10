<?php
// Payment pages for organizers, participants and Connect recipients. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\PaymentFacade;
use App\Security\Auth;
use DomainException;
use RuntimeException;

final class PaymentController extends Controller
{
    private PaymentFacade $payments;

    public function __construct()
    {
        $this->payments = new PaymentFacade();
    }

    public function index(): void
    {
        $account = Auth::requireLogin();

        $this->view('payment', [
            'title' => 'Payments',
            'mode' => 'dashboard',
            'payments' => $this->payments->paymentsForUser($account->getBaseUserId()),
            'connect' => $this->payments->connectStatus($account->getBaseUserId()),
        ]);
    }

    public function venue(): void
    {
        $account = Auth::requireLogin();
        $eventId = $this->queryId('eventId');

        if ($eventId === null) {
            $this->redirect(url('event', 'mine'));
        }

        try {
            $checkout = $this->payments->prepareVenueCheckout($eventId, $account->getBaseUserId());
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect(url('event', 'show', ['id' => $eventId]));
        }

        $this->renderCheckout($checkout, $eventId);
    }

    public function participant(): void
    {
        $account = Auth::requireLogin();
        $eventId = $this->queryId('eventId');

        if ($eventId === null) {
            $this->redirect(url('event'));
        }

        try {
            $checkout = $this->payments->prepareParticipantCheckout($eventId, $account->getBaseUserId());
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect(url('event', 'show', ['id' => $eventId]));
        }

        $this->renderCheckout($checkout, $eventId);
    }

    public function cancelParticipant(): void
    {
        $this->requirePostWithCsrf();
        $account = Auth::requireLogin();
        $eventId = (string) ($_POST['eventId'] ?? '');

        try {
            $this->payments->cancelParticipantPayment($eventId, $account->getBaseUserId());
            $this->flash('success', 'Your registration was cancelled and the full fee was refunded.');
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect($this->paymentUrl());
    }

    public function connect(): void
    {
        $this->requirePostWithCsrf();
        $account = Auth::requireLogin();
        $base = rtrim((string) config('app.base_url'), '/');
        $returnUrl = $base . '/payment.php?action=connectReturn';
        $refreshUrl = $base . '/payment.php';

        try {
            $url = $this->payments->beginConnectOnboarding(
                $account->getBaseUserId(),
                $account->getEmail(),
                $returnUrl,
                $refreshUrl
            );
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($this->paymentUrl());
        }

        $this->redirect($url);
    }

    public function connectReturn(): void
    {
        $account = Auth::requireLogin();

        try {
            $status = $this->payments->refreshConnectAccount($account->getBaseUserId());
            $this->flash(
                $status['payoutsEnabled'] ? 'success' : 'error',
                $status['payoutsEnabled']
                    ? 'Stripe Connect is ready to receive transfers.'
                    : 'Stripe still needs more account information.'
            );
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect($this->paymentUrl());
    }

    public function result(): void
    {
        Auth::requireLogin();
        $eventId = $this->queryId('eventId') ?? '';
        $kind = ($_GET['kind'] ?? '') === 'participant' ? 'participant' : 'venue';

        $this->view('payment', [
            'title' => 'Payment submitted',
            'mode' => 'result',
            'kind' => $kind,
            'eventId' => $eventId,
        ]);
    }

    /** @param array<string,mixed> $checkout */
    private function renderCheckout(array $checkout, string $eventId): void
    {
        if (($checkout['status'] ?? null) === 'PAID') {
            $this->flash('success', 'This payment is already complete.');
            $destination = $checkout['kind'] === 'venue'
                ? url('event', 'finalise', ['id' => $eventId])
                : $this->paymentUrl();
            $this->redirect($destination);
        }

        $base = rtrim((string) config('app.base_url'), '/');
        $kind = (string) $checkout['kind'];

        $this->view('payment', [
            'title' => $kind === 'venue' ? 'Pay venue booking' : 'Pay participant fee',
            'mode' => 'checkout',
            'checkout' => $checkout,
            'publishableKey' => (string) config('payment.stripe_public', ''),
            'returnUrl' => $base . '/payment.php?action=result&kind='
                . rawurlencode($kind) . '&eventId=' . rawurlencode($eventId),
        ]);
    }

    /** @param array<string,string> $params */
    private function paymentUrl(string $action = 'index', array $params = []): string
    {
        $query = $action === 'index' ? $params : ['action' => $action] + $params;

        return 'payment.php' . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
