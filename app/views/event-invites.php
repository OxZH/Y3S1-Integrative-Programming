<?php
// Invite link management. Author: Goh Jian Yu
// Receives: $event, $invites
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
                    [$state, $stateClass] = ['Revoked', 'dead'];
                } elseif ($invite->hasExpired()) {
                    [$state, $stateClass] = ['Expired', 'dead'];
                } elseif ($invite->isExhausted()) {
                    [$state, $stateClass] = ['Used up', 'dead'];
                } else {
                    [$state, $stateClass] = ['Working', 'live'];
                }
                ?>
                <tr>
                    <td class="mono">
                        <?php
                        // The flex row sits inside the cell rather than on it.
                        // Putting display:flex on the <td> itself stops it being
                        // a cell and pulls the whole table out of line.
                        ?>
                        <div class="copy-row">
                            <span class="copy-text"><?= e($invite->getShareableUrl()) ?></span>
                            <button class="btn ghost small copy-btn" type="button"
                                    data-copy="<?= e($invite->getShareableUrl()) ?>"
                                    title="Copy this link">
                                <svg class="copy-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                    <path d="M10 1H4a2 2 0 0 0-2 2v8h1.5V3A.5.5 0 0 1 4 2.5h6z"/>
                                    <path d="M12.5 4h-6A1.5 1.5 0 0 0 5 5.5v8A1.5 1.5 0 0 0 6.5 15h6a1.5 1.5 0 0 0 1.5-1.5v-8A1.5 1.5 0 0 0 12.5 4m0 1.5v8h-6v-8z"/>
                                </svg>
                                <span class="copy-label">Copy</span>
                            </button>
                        </div>
                    </td>
                    <td class="small">
                        <?= e((string) $invite->getUseCount()) ?><?= $invite->getMaxUses() !== null ? ' / ' . e((string) $invite->getMaxUses()) : '' ?>
                    </td>
                    <td class="small">
                        <?= $invite->getExpiresAt() !== null
                            ? e($invite->getExpiresAt()->format('d M Y, H:i'))
                            : '<span class="muted">Never</span>' ?>
                    </td>
                    <td><span class="pill <?= e($stateClass) ?>"><?= e($state) ?></span></td>
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
