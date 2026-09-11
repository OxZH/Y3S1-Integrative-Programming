<?php
// Administrator account list. Author: Ivan Lim Tze Yang

/** @var \App\Model\Account[] $accounts */
?>
<div class="page-head">
    <div>
        <h1>Accounts</h1>
        <p class="lede-flush"><?= count($accounts) ?> registered.</p>
    </div>
    <div class="toolbar">
        <a class="btn ghost" href="<?= e(url('admin', 'audit')) ?>">Security log</a>
        <a class="btn ghost" href="<?= e(url('admin', 'spam')) ?>">Possible spam</a>
    </div>
</div>

<div class="card">
    <table class="card-table">
        <thead>
            <tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Last sign-in</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('profile', 'index', ['id' => $account->getBaseUserId()])) ?>">
                            <?= e($account->getUsername()) ?>
                        </a>
                    </td>
                    <td class="mono small"><?= e($account->getMaskedEmail()) ?></td>
                    <td><?= e($account->getUserType()->label()) ?></td>
                    <td>
                        <span class="pill <?= $account->isActive() ? 'live' : 'dead' ?>">
                            <?= e($account->getAccountStatus()->label()) ?>
                        </span>
                    </td>
                    <td class="small"><?= e($account->getRegisterTime()?->format('Y-m-d') ?? '-') ?></td>
                    <td class="small"><?= e($account->getLastLoginAt()?->format('Y-m-d H:i') ?? 'Never') ?></td>
                    <td class="actions">
                        <?php if (!$account->isActive()): ?>
                            <form method="post" action="<?= e(url('admin', 'reactivate')) ?>" class="inline-form"
                                  data-confirm="Reactivate this account?">
                                <?= $csrfField ?>
                                <input type="hidden" name="baseUserId" value="<?= e($account->getBaseUserId()) ?>">
                                <button class="btn ghost small" type="submit">Reactivate</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
