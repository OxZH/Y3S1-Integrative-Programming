<?php
// Page chrome. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

use App\Security\Auth;

$currentUser = Auth::user();
$assets      = rtrim((string) config('app.base_url'), '/');

// Appending the file's modified time makes the browser fetch a fresh copy after
// an edit, instead of serving a cached stylesheet.
$assetVersion = static function (string $relative) use ($assets): string {
    $path = dirname(__DIR__, 2) . '/public/' . $relative;

    return $assets . '/' . $relative . '?v=' . (is_file($path) ? (string) filemtime($path) : '1');
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Sports Platform') ?></title>
    <link rel="stylesheet" href="<?= e($assetVersion('css/style.css')) ?>">
    <script src="<?= e($assetVersion('js/app.js')) ?>" defer></script>
</head>

<body>

    <header class="site-header">
        <div class="site-bar">
            <a class="brand" href="<?= e(url('event')) ?>">Sports<span class="brand-accent">Platform</span></a>

            <nav class="site-nav">
                <a href="<?= e(url('event')) ?>">Upcoming games</a>
                <?php if ($currentUser !== null): ?>
                    <a href="<?= e(url('event', 'mine')) ?>">My events</a>
                    <a href="<?= e(url('friends', 'mine')) ?>">My friends</a>
                    <?php if ($currentUser->isFacilityOwner()): ?>
                        <a href="<?= e(url('facility', 'mine')) ?>">My venues</a>
                    <?php endif; ?>
                    <a href="<?= e(url('profile')) ?>">My profile</a>
                    <?php if ($currentUser->isAdmin()): ?>
                        <a href="<?= e(url('admin', 'accounts')) ?>">Accounts</a>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>

            <div class="session">
                <?php if ($currentUser !== null): ?>
                    <span>Signed in as <strong><?= e($currentUser->getUsername()) ?></strong></span>
                    <form method="post" action="<?= e(url('auth', 'logout')) ?>" class="inline-form">
                        <?= $csrfField ?? '' ?>
                        <button class="btn ghost small" type="submit">Sign out</button>
                    </form>
                <?php else: ?>
                    <a class="btn ghost small" href="<?= e(url('auth')) ?>">Sign in</a>
                    <a class="btn small" href="<?= e(url('auth', 'register')) ?>">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="page">
        <?php foreach (($flash ?? []) as $note): ?>
            <div class="flash <?= e($note['type']) ?>"><?= e($note['message']) ?></div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>

</body>

</html>