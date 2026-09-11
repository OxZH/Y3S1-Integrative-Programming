<?php
// Shared internal demo payment page. Author: Khor Zhi Hong

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
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Select payment method</h2>
            <form method="post" action="payment.php?action=confirm">
                <?= $csrfField ?>
                <input type="hidden" name="eventId" value="<?= e($eventId) ?>">
                <input type="hidden" name="kind" value="<?= e($kind) ?>">

                <label><input type="radio" name="paymentMethod" value="card" required> Card</label>
                <label><input type="radio" name="paymentMethod" value="fpx" required> FPX Online Banking</label>
                <label><input type="radio" name="paymentMethod" value="e_wallet" required> E-wallet</label>

                <p class="small muted">Demo only: no real bank account or card will be charged.</p>
                <button class="btn" type="submit">
                    Confirm <?= e(money((float) $checkout['amount'])) ?>
                </button>
            </form>
        </div>
    </div>

<?php else: ?>
    <div class="page-head">
        <div>
            <h1>Payments</h1>
            <p class="lede lede-flush">Internal demo venue charges, participant fees and refunds.</p>
        </div>
    </div>

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
                            <?= e(str_replace('_', ' ', (string) $payment['kind'])) ?> ·
                            <?= $payment['direction'] === 'INCOMING' ? 'RECEIVED FROM' : 'PAID TO' ?>
                            <?= e((string) $payment['counterparty']) ?> ·
                            <?= e((string) $payment['paymentStatus']) ?>
                            <?php if (!empty($payment['createdAt'])): ?>
                                · <?= e(date('d M Y H:i', strtotime((string) $payment['createdAt']))) ?>
                            <?php endif; ?>
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
