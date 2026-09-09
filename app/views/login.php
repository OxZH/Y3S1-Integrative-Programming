<?php
// Demo account picker. Author: Goh Jian Yu
// Receives: $owners, $users
?>
<h1>Choose a demo account</h1>
<p class="lede">Temporary stand-in for the User Authentication module.</p>

<div class="banner">
    No password is checked here. This screen exists only until the authentication
    module is wired in.
</div>

<div class="row">
    <div class="card">
        <h2>Facility owners</h2>
        <p class="small muted">Can register and manage venues.</p>
        <?php foreach ($owners as $owner): ?>
            <form class="choice" method="post" action="<?= e(url('login', 'login')) ?>">
                <?= $csrfField ?>
                <input type="hidden" name="baseUserId" value="<?= e($owner->getBaseUserId()) ?>">
                <button class="btn ghost small block" type="submit">
                    <?= e($owner->getUsername()) ?> &middot; <span class="muted"><?= e($owner->getEmail()) ?></span>
                </button>
            </form>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Players</h2>
        <p class="small muted">Can organise events and share invite links.</p>
        <?php foreach ($users as $user): ?>
            <form class="choice" method="post" action="<?= e(url('login', 'login')) ?>">
                <?= $csrfField ?>
                <input type="hidden" name="baseUserId" value="<?= e($user->getBaseUserId()) ?>">
                <button class="btn ghost small block" type="submit">
                    <?= e($user->getUsername()) ?> &middot; <span class="muted"><?= e($user->getEmail()) ?></span>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
</div>
