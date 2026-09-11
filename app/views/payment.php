<?php
// Shared internal demo payment page. Author: Khor Zhi Hong

use App\Domain\Payment\EWalletPaymentStrategy;
use App\Domain\Payment\FpxPaymentStrategy;
use App\Domain\Payment\PaymentMethodStrategyFactory;

$mode = $mode ?? 'dashboard';
$input = is_array($input ?? null) ? $input : [];
$savedMethods = is_array($savedMethods ?? null) ? $savedMethods : [];
$strategies = is_array($strategies ?? null) ? $strategies : PaymentMethodStrategyFactory::all();
$selectedSavedId = (string) ($selectedSavedId ?? '');
$selectedMethod = (string) ($selectedMethod ?? '');

$raw = static function (string $name, string $default = '') use ($input): string {
    $value = $input[$name] ?? $default;

    return is_scalar($value) ? (string) $value : '';
};

$field = static function (string $name, string $default = '') use ($raw): string {
    return e($raw($name, $default));
};

$isSelected = static function (string $name, string $value) use ($raw): bool {
    return $raw($name) === $value;
};

$historyBits = static function (array $payment): string {
    $parts = [str_replace('_', ' ', (string) $payment['kind'])];
    $methodLabel = PaymentMethodStrategyFactory::displayLabel(
        isset($payment['paymentMethod']) ? (string) $payment['paymentMethod'] : null
    );

    if ($methodLabel !== '') {
        $parts[] = $methodLabel;
    }

    foreach (['accountMask', 'providerLabel'] as $key) {
        $value = trim((string) ($payment[$key] ?? ''));

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $parts[] = $payment['direction'] === 'INCOMING' ? 'RECEIVED FROM' : 'PAID TO';
    $parts[] = (string) $payment['counterparty'];
    $parts[] = (string) $payment['paymentStatus'];

    if (!empty($payment['createdAt'])) {
        $parts[] = date('d M Y H:i', strtotime((string) $payment['createdAt']));
    }

    return implode(' · ', $parts);
};

$hideMethod = static function (string $code) use ($selectedMethod): bool {
    return $selectedMethod !== '' && $selectedMethod !== $code;
};

$expiryYears = [];
$yearNow = (int) date('y');

for ($offset = 0; $offset <= 15; $offset++) {
    $expiryYears[] = str_pad((string) (($yearNow + $offset) % 100), 2, '0', STR_PAD_LEFT);
}
?>

<?php if ($mode === 'checkout'): ?>
    <?php
    $event = $checkout['event'];
    $kind = $checkout['kind'];
    ?>
    <div class="page-head">
        <div>
            <h1><?= $kind === 'venue' ? 'Pay venue booking' : 'Pay participant fee' ?></h1>
            <p class="lede lede-flush"><?= e((string) ($event['name'] ?? 'Event')) ?></p>
        </div>
        <span class="pill wait">Demo Payment</span>
    </div>

    <div class="row row-spaced">
        <div class="card">
            <h2>Payment details</h2>
            <div class="stat">
                <div><span>Date</span><strong><?= e((string) ($event['eventDate'] ?? '')) ?></strong></div>
                <div><span>Time</span><strong><?= e(hhmm((string) ($event['startTime'] ?? ''))) ?></strong></div>
                <div><span>Amount</span><strong><?= e(money((float) $checkout['amount'])) ?></strong></div>
            </div>
            <?php if ($kind === 'venue'): ?>
                <p class="small muted">
                    This demo records the venue fee as paid to
                    <?= e((string) ($checkout['facility']['owner']['username'] ?? 'the facility owner')) ?>.
                    Venue payments are non-refundable.
                </p>
            <?php else: ?>
                <p class="small muted">
                    A cancellation before the event starts receives a full refund.
                    After completion, the fee is recorded as settled to the organizer.
                    Free events still need a payment method so the checkout strategy is exercised.
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Select payment method</h2>
            <form method="post" action="payment.php?action=confirm" data-payment-checkout>
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e($eventId) ?>">
                <input type="hidden" name="kind" value="<?= e($kind) ?>">

                <?php if ($savedMethods !== []): ?>
                    <label for="savedPaymentMethodId">Use saved method</label>
                    <select id="savedPaymentMethodId" name="savedPaymentMethodId" data-saved-methods>
                        <option value="">Enter new details</option>
                        <?php foreach ($savedMethods as $saved): ?>
                            <?php
                            $autofillJson = json_encode($saved['autofill'] ?? [], JSON_UNESCAPED_UNICODE);
                            $savedId = (string) $saved['savedPaymentMethodId'];
                            ?>
                            <option value="<?= e($savedId) ?>"
                                    data-method="<?= e((string) $saved['paymentMethod']) ?>"
                                    data-autofill="<?= e(is_string($autofillJson) ? $autofillJson : '{}') ?>"
                                <?= $selectedSavedId === $savedId ? 'selected' : '' ?>>
                                <?= e((string) $saved['label']) ?>
                                · <?= e((string) $saved['accountMask']) ?>
                                <?php if ((int) ($saved['isDefault'] ?? 0) === 1): ?>
                                    (default)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="small muted">Choosing a saved method fills the form. CVV is never stored.</p>
                <?php endif; ?>

                <div class="choice-list">
                    <?php foreach ($strategies as $strategy): ?>
                        <label class="inline-label">
                            <input type="radio" name="paymentMethod" value="<?= e($strategy->code()) ?>"
                                <?= $selectedMethod === $strategy->code() ? 'checked' : '' ?> required>
                            <?= e($strategy->label()) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div data-method-fields="card"<?= $hideMethod('card') ? ' hidden' : '' ?>>
                    <label for="holderName">Cardholder name</label>
                    <input type="text" id="holderName" name="holderName" maxlength="100"
                           autocomplete="cc-name" value="<?= $field('holderName') ?>">

                    <label for="cardNumber">Card number</label>
                    <input type="text" id="cardNumber" name="cardNumber" maxlength="23"
                           inputmode="numeric" autocomplete="cc-number"
                           placeholder="4242 4242 4242 4242" value="<?= $field('cardNumber') ?>">

                    <div class="row">
                        <div>
                            <label for="expiryMonth">Expiry month</label>
                            <select id="expiryMonth" name="expiryMonth" autocomplete="cc-exp-month">
                                <option value="">MM</option>
                                <?php for ($month = 1; $month <= 12; $month++): ?>
                                    <?php $mm = str_pad((string) $month, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?= e($mm) ?>" <?= $isSelected('expiryMonth', $mm) ? 'selected' : '' ?>>
                                        <?= e($mm) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label for="expiryYear">Expiry year</label>
                            <select id="expiryYear" name="expiryYear" autocomplete="cc-exp-year">
                                <option value="">YY</option>
                                <?php foreach ($expiryYears as $yy): ?>
                                    <option value="<?= e($yy) ?>" <?= $isSelected('expiryYear', $yy) ? 'selected' : '' ?>>
                                        <?= e($yy) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label for="cvv">CVV</label>
                    <input type="password" id="cvv" name="cvv" maxlength="4" inputmode="numeric"
                           autocomplete="cc-csc" data-no-copy>
                </div>

                <div data-method-fields="fpx"<?= $hideMethod('fpx') ? ' hidden' : '' ?>>
                    <label for="bankName">Bank</label>
                    <select id="bankName" name="bankName">
                        <option value="">Choose a bank</option>
                        <?php foreach (FpxPaymentStrategy::banks() as $bank): ?>
                            <option value="<?= e($bank) ?>" <?= $isSelected('bankName', $bank) ? 'selected' : '' ?>>
                                <?= e($bank) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="accountHolder">Account holder</label>
                    <input type="text" id="accountHolder" name="accountHolder" maxlength="100"
                           value="<?= $field('accountHolder') ?>">

                    <label for="accountNumber">Account number</label>
                    <input type="text" id="accountNumber" name="accountNumber" maxlength="20"
                           inputmode="numeric" value="<?= $field('accountNumber') ?>">
                </div>

                <div data-method-fields="e_wallet"<?= $hideMethod('e_wallet') ? ' hidden' : '' ?>>
                    <label for="walletProvider">Wallet</label>
                    <select id="walletProvider" name="walletProvider">
                        <option value="">Choose a wallet</option>
                        <?php foreach (EWalletPaymentStrategy::providers() as $provider): ?>
                            <option value="<?= e($provider) ?>" <?= $isSelected('walletProvider', $provider) ? 'selected' : '' ?>>
                                <?= e($provider) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="walletAccount">Wallet phone or account id</label>
                    <input type="text" id="walletAccount" name="walletAccount" maxlength="20"
                           placeholder="0123456789" value="<?= $field('walletAccount') ?>">
                </div>

                <label class="inline-check-label">
                    <input class="inline-check" type="checkbox" name="saveMethod" value="1">
                    Save this method after paying
                </label>

                <p class="small muted">Demo only: no real bank account or card will be charged.</p>
                <button class="btn" type="submit">
                    Confirm <?= e(money((float) $checkout['amount'])) ?>
                </button>
            </form>
        </div>
    </div>

<?php elseif ($mode === 'offerSave'): ?>
    <?php
    $pending = is_array($pending ?? null) ? $pending : [];
    $methodLabel = PaymentMethodStrategyFactory::displayLabel(
        isset($pending['paymentMethod']) ? (string) $pending['paymentMethod'] : null
    );
    ?>
    <div class="page-head">
        <div>
            <h1>Save this payment method?</h1>
            <p class="lede lede-flush">Use it to fill the checkout form next time.</p>
        </div>
        <span class="pill wait">Demo Payment</span>
    </div>

    <div class="card">
        <p>
            <?= e($methodLabel) ?>
            · <?= e((string) ($pending['accountMask'] ?? '')) ?>
            · <?= e((string) ($pending['providerLabel'] ?? '')) ?>
        </p>
        <p class="small muted">The card number and CVV are not stored. Only a masked snapshot is kept.</p>

        <form method="post" action="payment.php?action=saveMethod">
            <?= $csrfField ?>
            <label for="label">Nickname</label>
            <input type="text" id="label" name="label" maxlength="100" required
                   value="<?= e((string) ($suggestedLabel ?? 'My saved method')) ?>">

            <label class="inline-check-label">
                <input class="inline-check" type="checkbox" name="isDefault" value="1">
                Set as default
            </label>

            <div class="button-row">
                <button class="btn" type="submit">Save</button>
            </div>
        </form>

        <form method="post" action="payment.php?action=skipSave" class="form-actions">
            <?= $csrfField ?>
            <button class="btn ghost" type="submit">Skip</button>
        </form>
    </div>

<?php else: ?>
    <div class="page-head">
        <div>
            <h1>Payments</h1>
            <p class="lede lede-flush">Internal demo venue charges, participant fees and refunds.</p>
        </div>
    </div>

    <h2>Saved methods</h2>
    <?php if ($savedMethods === []): ?>
        <p class="small muted">No saved methods yet. You can save one after the next demo payment.</p>
    <?php else: ?>
        <div class="card">
            <?php foreach ($savedMethods as $saved): ?>
                <div class="toolbar">
                    <div>
                        <strong><?= e((string) $saved['label']) ?></strong>
                        <?php if ((int) ($saved['isDefault'] ?? 0) === 1): ?>
                            <span class="pill live">Default</span>
                        <?php endif; ?>
                        <p class="small muted sub">
                            <?= e(PaymentMethodStrategyFactory::displayLabel((string) $saved['paymentMethod'])) ?>
                            · <?= e((string) $saved['accountMask']) ?>
                            · <?= e((string) $saved['providerLabel']) ?>
                        </p>
                    </div>
                    <?php if ((int) ($saved['isDefault'] ?? 0) !== 1): ?>
                        <form method="post" action="payment.php?action=setDefaultSavedMethod">
                            <?= $csrfField ?>
                            <input type="hidden" name="savedPaymentMethodId"
                                   value="<?= e((string) $saved['savedPaymentMethodId']) ?>">
                            <button class="btn ghost small" type="submit">Set default</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="payment.php?action=deleteSavedMethod"
                          data-confirm="Remove this saved payment method?">
                        <?= $csrfField ?>
                        <input type="hidden" name="savedPaymentMethodId"
                               value="<?= e((string) $saved['savedPaymentMethodId']) ?>">
                        <button class="btn danger small" type="submit">Delete</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2>Transfer history</h2>
    <?php if ($payments === []): ?>
        <div class="empty"><p>No payments yet.</p></div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($payments as $payment): ?>
                <div class="toolbar">
                    <div>
                        <strong>
                            <?= $payment['direction'] === 'INCOMING' ? '+' : '-' ?>
                            <?= e(money((float) $payment['amount'])) ?>
                            · <?= e((string) $payment['name']) ?>
                        </strong>
                        <p class="small muted sub">
                            <?= e($historyBits($payment)) ?>
                        </p>
                    </div>
                    <a class="btn ghost small" href="<?= e(url('event', 'show', ['id' => $payment['eventId']])) ?>">View event</a>
                    <?php if (
                        $payment['kind'] === 'PARTICIPANT_FEE'
                        && $payment['direction'] === 'OUTGOING'
                        && $payment['paymentStatus'] === 'PAID'
                    ): ?>
                        <form method="post" action="payment.php?action=cancelParticipant"
                              data-confirm="Cancel registration and request a full refund?">
                            <?= $csrfField ?>
                            <input type="hidden" name="eventId" value="<?= e((string) $payment['eventId']) ?>">
                            <button class="btn danger small" type="submit">Cancel and refund</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
