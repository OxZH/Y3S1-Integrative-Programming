<?php
// Internal demo payment pages for organizers and participants. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\Payment\PaymentMethodStrategyFactory;
use App\Domain\PaymentFacade;
use App\Security\Auth;
use DomainException;
use RuntimeException;

final class PaymentController extends Controller
{
    private const PENDING_SAVE_KEY = 'pendingSavedPayment';
    private const CHECKOUT_INPUT_KEY = 'paymentCheckoutInput';
    private const PENDING_SAVE_TTL = 1800;

    private PaymentFacade $payments;

    public function __construct()
    {
        $this->payments = new PaymentFacade();
    }

    public function index(): void
    {
        $account = Auth::requireLogin();
        $userId = $account->getBaseUserId();

        $this->view('payment', [
            'title' => 'Payments',
            'mode' => 'dashboard',
            'payments' => $this->payments->paymentsForUser($userId),
            'savedMethods' => $this->payments->savedMethodsForUser($userId),
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

        $this->renderCheckout($checkout, $eventId, $account->getBaseUserId());
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

        $this->renderCheckout($checkout, $eventId, $account->getBaseUserId());
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
        $userId = $account->getBaseUserId();

        try {
            if ($kind === 'venue') {
                $this->payments->prepareVenueCheckout($eventId, $userId);
            } elseif ($kind === 'participant') {
                $this->payments->prepareParticipantCheckout($eventId, $userId);
            } else {
                throw new DomainException('The payment type is invalid.');
            }

            $snapshot = $this->payments->confirmInternalPayment(
                $eventId,
                $userId,
                $kind,
                $method,
                $_POST
            );

            if (($_POST['saveMethod'] ?? '') === '1') {
                try {
                    $this->payments->savePaymentMethod(
                        $userId,
                        $snapshot,
                        $this->defaultSavedLabel($snapshot),
                        false
                    );
                    $this->flash('success', 'Demo payment confirmed. The method was saved for next time.');
                    $this->redirect($this->destinationAfterPayment($kind, $eventId));
                } catch (DomainException | RuntimeException $e) {
                    $this->flash('error', $e->getMessage());
                }
            }

            $this->flash('success', 'Demo payment confirmed.');

            $_SESSION[self::PENDING_SAVE_KEY] = [
                'userId' => $userId,
                'eventId' => $eventId,
                'kind' => $kind,
                'paymentMethod' => $snapshot['paymentMethod'],
                'payerName' => $snapshot['payerName'],
                'accountMask' => $snapshot['accountMask'],
                'providerLabel' => $snapshot['providerLabel'],
                'methodDetailJson' => $snapshot['methodDetailJson'],
                'createdAt' => time(),
            ];
            $this->redirect($this->paymentUrl('offerSave'));
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $posted = $_POST;
            unset($posted['cvv'], $posted['_token']);
            $_SESSION[self::CHECKOUT_INPUT_KEY] = $posted;
            $this->redirect($kind === 'venue'
                ? $this->paymentUrl('venue', ['eventId' => $eventId])
                : ($kind === 'participant'
                    ? $this->paymentUrl('participant', ['eventId' => $eventId])
                    : $this->paymentUrl()));
        }
    }

    public function offerSave(): void
    {
        $account = Auth::requireLogin();
        $pending = $this->pendingSaveFor($account->getBaseUserId());

        if ($pending === null) {
            $this->redirect($this->paymentUrl());
        }

        $this->view('payment', [
            'title' => 'Save payment method',
            'mode' => 'offerSave',
            'pending' => $pending,
            'suggestedLabel' => $this->defaultSavedLabel($pending),
        ]);
    }

    public function saveMethod(): void
    {
        $this->requirePostWithCsrf();
        $account = Auth::requireLogin();
        $pending = $this->pendingSaveFor($account->getBaseUserId());

        if ($pending === null) {
            $this->flash('error', 'There is nothing left to save from that payment.');
            $this->redirect($this->paymentUrl());
        }

        try {
            $this->payments->savePaymentMethod(
                $account->getBaseUserId(),
                $pending,
                (string) ($_POST['label'] ?? ''),
                ($_POST['isDefault'] ?? '') === '1'
            );
            $this->clearPendingSave();
            $this->flash('success', 'Payment method saved for next time.');
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($this->paymentUrl('offerSave'));
        }

        $this->redirect($this->destinationAfterPayment(
            (string) $pending['kind'],
            (string) $pending['eventId']
        ));
    }

    public function skipSave(): void
    {
        $this->requirePostWithCsrf();
        $account = Auth::requireLogin();
        $pending = $this->pendingSaveFor($account->getBaseUserId());
        $this->clearPendingSave();

        $this->redirect($pending === null
            ? $this->paymentUrl()
            : $this->destinationAfterPayment(
                (string) $pending['kind'],
                (string) $pending['eventId']
            ));
    }

    public function deleteSavedMethod(): void
    {
        $this->requirePostWithCsrf();
        $account = Auth::requireLogin();

        try {
            $this->payments->deleteSavedMethod(
                $account->getBaseUserId(),
                (string) ($_POST['savedPaymentMethodId'] ?? '')
            );
            $this->flash('success', 'Saved payment method removed.');
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect($this->paymentUrl());
    }

    public function setDefaultSavedMethod(): void
    {
        $this->requirePostWithCsrf();
        $account = Auth::requireLogin();

        try {
            $this->payments->setDefaultSavedMethod(
                $account->getBaseUserId(),
                (string) ($_POST['savedPaymentMethodId'] ?? '')
            );
            $this->flash('success', 'Default payment method updated.');
        } catch (DomainException | RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect($this->paymentUrl());
    }

    /** @param array<string,mixed> $checkout */
    private function renderCheckout(array $checkout, string $eventId, string $userId): void
    {
        if (($checkout['status'] ?? null) === 'PAID') {
            $this->flash('success', 'This payment is already complete.');
            $destination = $checkout['kind'] === 'venue'
                ? url('event', 'finalise', ['id' => $eventId])
                : $this->paymentUrl();
            $this->redirect($destination);
        }

        $kind = (string) $checkout['kind'];
        $savedMethods = $this->payments->savedMethodsForUser($userId);
        $input = $this->takeCheckoutInput();
        $postedSavedId = array_key_exists('savedPaymentMethodId', $input);
        $selectedSavedId = trim((string) ($input['savedPaymentMethodId'] ?? ''));
        $fill = [];

        if (!$postedSavedId) {
            foreach ($savedMethods as $saved) {
                if ((int) ($saved['isDefault'] ?? 0) === 1) {
                    $selectedSavedId = (string) $saved['savedPaymentMethodId'];
                    break;
                }
            }
        }

        foreach ($savedMethods as $saved) {
            if ((string) $saved['savedPaymentMethodId'] === $selectedSavedId) {
                $fill = is_array($saved['autofill'] ?? null) ? $saved['autofill'] : [];
                break;
            }
        }

        $selectedMethod = trim((string) ($input['paymentMethod'] ?? ''));

        if ($selectedMethod === '' && $selectedSavedId !== '') {
            foreach ($savedMethods as $saved) {
                if ((string) $saved['savedPaymentMethodId'] === $selectedSavedId) {
                    $selectedMethod = (string) $saved['paymentMethod'];
                    break;
                }
            }
        }

        $merged = $fill;

        foreach ($input as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $merged[$key] = $value;
            }
        }

        $this->view('payment', [
            'title' => $kind === 'venue' ? 'Pay venue booking' : 'Pay participant fee',
            'mode' => 'checkout',
            'checkout' => $checkout,
            'eventId' => $eventId,
            'savedMethods' => $savedMethods,
            'strategies' => PaymentMethodStrategyFactory::all(),
            'input' => $merged,
            'selectedSavedId' => $selectedSavedId,
            'selectedMethod' => $selectedMethod,
        ]);
    }

    /** @return array<string,mixed> */
    private function takeCheckoutInput(): array
    {
        $input = $_SESSION[self::CHECKOUT_INPUT_KEY] ?? [];
        unset($_SESSION[self::CHECKOUT_INPUT_KEY]);

        return is_array($input) ? $input : [];
    }

    /** @return array<string,mixed>|null */
    private function pendingSaveFor(string $userId): ?array
    {
        $pending = $_SESSION[self::PENDING_SAVE_KEY] ?? null;

        if (!is_array($pending) || (string) ($pending['userId'] ?? '') !== $userId) {
            return null;
        }

        $createdAt = (int) ($pending['createdAt'] ?? 0);

        if ($createdAt < time() - self::PENDING_SAVE_TTL) {
            $this->clearPendingSave();

            return null;
        }

        return $pending;
    }

    private function clearPendingSave(): void
    {
        unset($_SESSION[self::PENDING_SAVE_KEY]);
    }

    /** @param array<string,mixed> $snapshot */
    private function defaultSavedLabel(array $snapshot): string
    {
        $provider = trim((string) ($snapshot['providerLabel'] ?? ''));

        return $provider === '' ? 'My saved method' : 'My ' . $provider;
    }

    private function destinationAfterPayment(string $kind, string $eventId): string
    {
        return $kind === 'venue'
            ? url('event', 'finalise', ['id' => $eventId])
            : url('event', 'show', ['id' => $eventId]);
    }

    /** @param array<string,string> $params */
    private function paymentUrl(string $action = 'index', array $params = []): string
    {
        $query = $action === 'index' ? $params : ['action' => $action] + $params;

        return 'payment.php' . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
