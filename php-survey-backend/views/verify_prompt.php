<?php
/** @var array $config */
/** @var string $verify_token */
/** @var int $token_expires_in */
$verify_token = isset($verify_token) ? (string) $verify_token : '';
$token_expires_in = isset($token_expires_in) ? (int) $token_expires_in : 0;
$expires_minutes = $token_expires_in > 0 ? ceil($token_expires_in / 60) : 0;
?>
<section class="flow-step">
  <p class="step-eyebrow">Step 2 · Quick check</p>
  <h2 class="step-title">Tap to prove it's really you</h2>
  <p class="flow-copy">
    Email providers sometimes check links automatically for security. This confirmation step prevents those checks from using up your one-time login link.
  </p>
  <?php if ($expires_minutes > 0): ?>
    <div class="count-pill">
      <strong>Link expires soon</strong>
      <span>Ready for <?= h($expires_minutes) ?> minute<?= $expires_minutes === 1 ? '' : 's' ?></span>
    </div>
  <?php endif; ?>
  <form method="post" class="flow-form">
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="token" value="<?= h($verify_token) ?>">
    <button type="submit" class="primary-btn verify-prompt-btn">Yes, take me to the announcement</button>
  </form>
  <p class="flow-support">
    If you didn’t request this link you can simply close the page—no action is taken.
  </p>
</section>
