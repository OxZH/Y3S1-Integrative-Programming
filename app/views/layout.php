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
        <?php // js part - home is Find a game ?>
        <a class="brand" href="<?= e(url('discovery')) ?>">Sports<span class="brand-accent">Platform</span></a>

        <?php
        // A venue owner cannot organise or join a game, so browsing them and
        // seeing them on a map is of no use to them. They get straight to their
        // venues instead. The pages themselves stay reachable, this only stops
        // offering them.
        $playsGames = $currentUser === null || !$currentUser->isFacilityOwner();
        ?>
        <?php
        // The navigation is built as groups rather than written out as a flat
        // row, because it had grown to nine links for a player and read as a
        // list of everything rather than a way around.
        //
        // A group holding one link is rendered as that link, not as a menu of
        // one. That is what keeps a venue owner's bar as short as it was: every
        // one of their groups collapses, so they still see three plain links.
        $groups = [];

        // -- somewhere to play, or a game to join ---------------------------
        $discover = [];

        if ($playsGames) {
            // js part - Discovery & Event Matchmaking
            $discover[] = ['Find a game', url('discovery')];
            $discover[] = ['Map', url('discovery', 'map')];

            if ($currentUser !== null) {
                $discover[] = ['Recommended', url('discovery', 'recommended')];
            }
            // /js part
        }

        // Venue search is open to anyone, since a player may want to see where
        // games can be held before organising one.
        $discover[] = ['Find a venue', url('facility', 'search')];

        $groups[] = ['Discover', $discover];

        if ($currentUser !== null) {
            // -- what this person is already part of ------------------------
            //
            // Organising and joining games belongs to a member account, so the
            // test is isPlayer() rather than "not an owner". An administrator is
            // neither, and My events answers 403 to them, so offering it was
            // offering a door that does not open.
            if ($currentUser->isFacilityOwner()) {
                // An owner cannot organise or join a game, so there is only the
                // one link and it stays a plain one.
                $groups[] = ['My venues', [['My venues', url('facility', 'mine')]]];
            } elseif ($currentUser->isPlayer()) {
                $groups[] = ['Events', [
                    ['My events', url('event', 'mine')],
                    // js part
                    ['My participation', url('discovery', 'mine')],
                    // /js part
                ]];
            }

            // -- the account, and the people on it --------------------------
            $profile = [['My profile', url('profile')]];

            if ($currentUser->isPlayer()) {
                // kw part - Social Networking & Review. Scoped to members, which
                // is how these two were written.
                $profile[] = ['My friends', url('friends', 'mine')];
                $profile[] = ['Discover users', url('profile', 'discover')];
                // /kw part
            }

            $groups[] = ['Profile', $profile];

            if ($currentUser->isAdmin()) {
                $groups[] = ['Admin', [
                    ['Accounts', url('admin', 'accounts')],
                    // kw part - the review moderation queue
                    ['Possible spam', url('admin', 'spam')],
                ]];
            }
        }
        ?>
        <nav class="site-nav">
            <?php foreach ($groups as [$label, $items]): ?>
                <?php if (count($items) === 1): ?>
                    <a class="nav-link" href="<?= e($items[0][1]) ?>"><?= e($items[0][0]) ?></a>
                <?php else: ?>
                    <div class="nav-group">
                        <?php
                        // The heading is a link to the first item as well as the
                        // way in to the menu, so the group is never a dead end
                        // for anybody who clicks rather than hovers.
                        ?>
                        <a class="nav-link nav-head" href="<?= e($items[0][1]) ?>">
                            <?= e($label) ?><span class="nav-caret" aria-hidden="true"></span>
                        </a>
                        <div class="nav-menu">
                            <?php foreach ($items as [$text, $href]): ?>
                                <a href="<?= e($href) ?>"><?= e($text) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
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