<?php
// Invite link management. Author: Goh Jian Yu

/** @var \App\Model\Event $event */
/** @var \App\Model\EventInvite[] $invites */
?>
<h1>Invite links</h1>
<p class="lede">
    For <strong><?= e($event->getName()) ?></strong>
    &middot; <?= e($event->getEventDate()->format('D, d M Y')) ?>
    &middot; <?= e($event->getVisibility()->label()) ?>
</p>

<div class="banner">
    Anyone holding a working link can open this event. Treat it like a password: give it an expiry
    or a use limit, and revoke it if it ends up somewhere public.
</div>

<div class="card">
    <h2>Create a new link</h2>
    <form method="post" action="<?= e(url('event', 'createInvite')) ?>">
        <?= $csrfField ?>
        <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">

        <div class="row">
            <div>
                <label for="expiresOn">Stops working after (optional)</label>
                <input type="date" id="expiresOn" name="expiresOn" min="<?= e(date('Y-m-d')) ?>">
            </div>
            <div>
                <label for="maxUses">Maximum uses (optional)</label>
                <input type="number" id="maxUses" name="maxUses" min="1" max="1000" placeholder="Unlimited">
            </div>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Generate link</button>
            <a class="btn ghost" href="<?= e(url('event', 'show', ['id' => $event->getEventId()])) ?>">Back to event</a>
        </div>
    </form>
</div>

<h2>Existing links</h2>

<?php if ($invites === []): ?>
    <div class="card empty"><p>No links yet.</p></div>
<?php else: ?>
    <div class="card card-table">
        <table>
            <thead>
            <tr><th>Link</th><th>Uses</th><th>Expires</th><th>State</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($invites as $invite): ?>
                <?php
                if ($invite->isRevoked()) {
                    [$state, $pill] = ['Revoked', 'dead'];
                } elseif ($invite->hasExpired()) {
                    [$state, $pill] = ['Expired', 'dead'];
                } elseif ($invite->isExhausted()) {
                    [$state, $pill] = ['Used up', 'dead'];
                } else {
                    [$state, $pill] = ['Working', 'live'];
                }
                ?>
                <tr>
                    <td class="mono"><?= e($invite->getShareableUrl()) ?></td>
                    <td class="small">
                        <?= e((string) $invite->getUseCount()) ?><?= $invite->getMaxUses() !== null ? ' / ' . e((string) $invite->getMaxUses()) : '' ?>
                    </td>
                    <td class="small">
                        <?= $invite->getExpiresAt() !== null
                            ? e($invite->getExpiresAt()->format('d M Y, H:i'))
                            : '<span class="muted">Never</span>' ?>
                    </td>
                    <td><span class="pill <?= e($pill) ?>"><?= e($state) ?></span></td>
                    <td>
                        <?php if (!$invite->isRevoked()): ?>
                            <form method="post" action="<?= e(url('event', 'revokeInvite')) ?>" class="inline-form">
                                <?= $csrfField ?>
                                <input type="hidden" name="eventInviteId" value="<?= e((string) $invite->getEventInviteId()) ?>">
                                <input type="hidden" name="eventId" value="<?= e((string) $event->getEventId()) ?>">
                                <button class="btn ghost small" type="submit">Revoke</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="small muted">Revoking is immediate and cannot be undone; generate a fresh link instead.</p>
<?php endif; ?>
