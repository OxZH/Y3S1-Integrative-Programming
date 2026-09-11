<?php
// Internal demo payment pages for organizers and participants. Author: Khor Zhi Hong

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
            $this->redirect(url('discovery'));
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

    public function confirm(): void
    {
        $this->requirePostWithCsrf();
        $account = Auth::requireLogin();
        $eventId = (string) ($_POST['eventId'] ?? '');
        $kind = (string) ($_POST['kind'] ?? '');
        $method = (string) ($_POST['paymentMethod'] ?? '');

        try {
            if ($kind === 'venue') {
                $this->payments->prepareVenueCheckout($eventId, $account->getBaseUserId());
            } elseif ($kind === 'participant') {
                $this->payments->prepareParticipantCheckout($eventId, $account->getBaseUserId());
            } else {
                throw new DomainException('The payment type is invalid.');
            }

            $this->payments->confirmInternalPayment(
                $eventId,
                $account->getBaseUserId(),
                $kind,
                $method
            );
            $this->flash('success', 'Demo payment confirmed.');
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect($kind === 'venue'
            ? url('event', 'finalise', ['id' => $eventId])
            : url('event', 'show', ['id' => $eventId]));
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

        $kind = (string) $checkout['kind'];

        $this->view('payment', [
            'title' => $kind === 'venue' ? 'Pay venue booking' : 'Pay participant fee',
            'mode' => 'checkout',
            'checkout' => $checkout,
            'eventId' => $eventId,
        ]);
    }

    /** @param array<string,string> $params */
    private function paymentUrl(string $action = 'index', array $params = []): string
    {
        $query = $action === 'index' ? $params : ['action' => $action] + $params;

        return 'payment.php' . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
