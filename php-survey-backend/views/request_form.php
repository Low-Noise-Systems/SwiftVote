<?php
/** @var array $config */
if (!isset($config) || !is_array($config)) {
  echo '<div class="alert alert-danger">Configuration unavailable.</div>';
  return;
}
?>
<section class="flow-step">
  <p class="step-eyebrow">Step 1 · Request access</p>
  <h2 class="step-title">Enter your email to receive your secure&nbsp;voting&nbsp;link</h2>
  <p class="flow-copy">We’ll send a one-time link to the inbox you provide below. Only authorized voters can continue.</p>
  <form method="post" class="flow-form" data-prevent-multi-submit>
    <input type="hidden" name="action" value="request_link">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label class="field-label">
      <span>Email address</span>
      <input class="type-field" type="email" name="email" required placeholder="you@company.com">
    </label>
    <?php include_once __DIR__ . '/../includes/turnstile.php'; render_turnstile_widget($config, 'submit-btn'); ?>
    <p class="flow-support">We use this email only to send your ballot link. It expires quickly to keep the vote secure.</p>
    <button type="submit" class="primary-btn" id="submit-btn" <?php if (!empty($config['turnstile_enabled'])): ?>disabled<?php endif; ?>>Send my access link</button>
  </form>
</section>
