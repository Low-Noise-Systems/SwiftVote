<?php
/** @var bool $is_admin */
$is_admin = isset($is_admin) ? (bool) $is_admin : false;
?>
    <h2>Vote already recorded</h2>
    <p class="muted">Your vote has been successfully recorded. Thank you for your participation!</p>
    <?php if ($is_admin): ?>
  <p class="admin-link"><a href="admin/">Access the admin panel</a></p>
    <?php endif; ?>
<?php
?>
