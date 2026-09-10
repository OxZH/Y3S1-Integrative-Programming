<?php
// Error page. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang
// Receives: $status, $heading, $message, $detail
?>
<div class="card message-card">
    <div class="pill dead"><?= e((string) $status) ?></div>
    <h1><?= e($heading) ?></h1>
    <p class="lede"><?= e($message) ?></p>

    <?php if (!empty($detail)): ?>
        <p class="mono muted message-detail"><?= e($detail) ?></p>
    <?php endif; ?>

    <a class="btn" href="<?= e(url('event')) ?>">Back to games</a>
</div>
