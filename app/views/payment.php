<?php
// Shared payment, payout and Connect page. Author: Khor Zhi Hong

$mode = $mode ?? 'dashboard';
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
        <span class="pill wait">Stripe Test Mode</span>
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
                    The platform collects this venue fee and transfers it to
                    <?= e((string) ($checkout['facility']['owner']['username'] ?? 'the facility owner')) ?>.
                    Venue payments are non-refundable.
                </p>
            <?php else: ?>
                <p class="small muted">
                    A cancellation before the event starts receives a full refund.
                    After completion, the platform transfers the fee to the organizer.
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Pay securely</h2>
            <?php if ($publishableKey === ''): ?>
                <div class="flash error">Set STRIPE_PUBLISHABLE_KEY before opening checkout.</div>
            <?php else: ?>
                <form id="stripePaymentForm"
                      data-publishable-key="<?= e($publishableKey) ?>"
                      data-client-secret="<?= e((string) $checkout['clientSecret']) ?>"
                      data-return-url="<?= e($returnUrl) ?>">
                    <div id="stripePaymentElement"></div>
                    <p id="stripePaymentMessage" class="small muted" role="status"></p>
                    <button class="btn" id="stripePaymentButton" type="submit">
                        Pay <?= e(money((float) $checkout['amount'])) ?>
                    </button>
                </form>
                <script src="https://js.stripe.com/v3/"></script>
                <script src="<?= e(rtrim((string) config('app.base_url'), '/') . '/js/payment.js?v='
                    . (string) filemtime(dirname(__DIR__, 2) . '/public/js/payment.js')) ?>" defer></script>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($mode === 'result'): ?>
    <h1>Payment submitted</h1>
    <div class="card">
        <p>Stripe is confirming the payment. The verified Webhook updates the final status.</p>
        <?php if ($kind === 'venue'): ?>
            <a class="btn" href="<?= e(url('event', 'finalise', ['id' => $eventId])) ?>">Continue to event confirmation</a>
        <?php else: ?>
            <a class="btn" href="<?= e(url('event', 'show', ['id' => $eventId])) ?>">Return to event</a>
        <?php endif; ?>
        <a class="btn ghost" href="payment.php">View payments</a>
    </div>

<?php else: ?>
    <div class="page-head">
        <div>
            <h1>Payments</h1>
            <p class="lede lede-flush">Venue charges, participant fees and Stripe Connect payouts.</p>
        </div>
    </div>

    <div class="card">
        <h2>Stripe Connect</h2>
        <?php if ($connect === null): ?>
            <p>Connect your Stripe Test account to receive venue transfers or organizer payouts.</p>
        <?php elseif ((bool) $connect['payoutsEnabled']): ?>
            <p><span class="pill live">Ready</span> This account can receive Stripe transfers.</p>
        <?php else: ?>
            <p><span class="pill wait">Incomplete</span> Stripe needs more information before transfers can be received.</p>
        <?php endif; ?>
        <form method="post" action="payment.php?action=connect">
            <?= $csrfField ?>
            <button class="btn" type="submit">
                <?= $connect === null ? 'Set up Stripe Connect' : 'Continue Stripe onboarding' ?>
            </button>
        </form>
    </div>

    <h2>My payments</h2>
    <?php if ($payments === []): ?>
        <div class="empty"><p>No payments yet.</p></div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($payments as $payment): ?>
                <div class="toolbar">
                    <div>
                        <strong><?= e((string) $payment['name']) ?></strong>
                        <p class="small muted sub">
                            <?= e((string) $payment['kind']) ?> ·
                            <?= e(money((float) $payment['amount'])) ?> ·
                            <?= e((string) $payment['paymentStatus']) ?>
                        </p>
                    </div>
                    <a class="btn ghost small" href="<?= e(url('event', 'show', ['id' => $payment['eventId']])) ?>">View event</a>
                    <?php if ($payment['kind'] === 'PARTICIPANT' && $payment['paymentStatus'] === 'PAID'): ?>
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
