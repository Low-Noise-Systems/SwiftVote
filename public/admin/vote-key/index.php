<?php
declare(strict_types=1);

// Secure entry point for the init tool; requires an authenticated admin session.
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/../../../php-survey-backend/includes/helpers.php';

$base_url = resolve_base_url($config);
$action = $_GET['action'] ?? $_POST['action'] ?? 'view';
if ($action === 'disconnect') {
	require_csrf($_POST['csrf'] ?? '');
	unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
	$_SESSION['flash'] = ['ok', 'Disconnected.'];
	header('Location: ' . $base_url); exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Initializing the tallying code</title>
  <link rel="stylesheet" href="../../assets/css/style.css?v=2">
  <link rel="stylesheet" href="../../assets/css/admin.css?v=2">
</head>
<body data-csrf-token="<?php echo h(csrf_token()); ?>" data-anonymous-export="<?php echo ($config['anonymous_voter_export'] ?? false) ? 'true' : 'false'; ?>">
<div class="card">
  <div class="back-link"><a href="../index.php">Back to admin panel</a></div>
  <h1>Manage the tallying key</h1>
  <p class="muted">This step must be performed before opening the vote: the key generated here secures each ballot and will be essential to obtain the results at the end of the poll.</p>
  <p class="muted">This interface is mainly used to verify that the secret phrase you have kept matches the key stored on the server. If no key has yet been defined for this poll, you can generate one here, once and only once.</p>
  <div class="alert alert-warning"><strong>Important:</strong> This 12-word phrase is the only way to open the vote results.<br>Keep it in a safe place.</div>
  <form id="init-form" class="hidden-section">
    <button type="submit">Create a new code</button>
  </form>
  <div id="mnemonic-section" class="hidden-section">
    <h2>Your secret phrase (12 words)</h2>
    <div id="mnemonic" class="mnemonic-box"></div>
    <div class="row">
      <label for="confirm-mnemonic" class="muted">Copy the 12 words to confirm:</label>
      <input type="text" id="confirm-mnemonic" autocomplete="off">
      <button id="confirm-btn">Confirm and publish the code</button>
      <div id="mnemonic-error" class="err hidden-section"></div>
    </div>
  </div>
  <section id="pubkey-section" class="hidden-section">
    <h2>Technical fingerprint of the code</h2>
    <p class="muted center">These lines are a technical fingerprint to verify that the code is stored on the server. They do not allow tallying votes without the secret phrase.</p>
    <div>
      <h3>Fingerprint (Base64)</h3>
      <div id="pubkey-b64" class="key-box"></div>
    </div>
    <div>
      <h3>Fingerprint (Hexadecimal)</h3>
      <div id="pubkey-hex" class="key-box"></div>
    </div>
    <p class="muted center">Recorded on <span id="pubkey-timestamp"></span></p>
    <hr>
    <h2>Verify your secret phrase</h2>
    <p class="muted">Enter your 12-word phrase to verify that it matches the registered code.</p>
    <form id="test-mnemonic-form">
      <textarea id="test-mnemonic-input" placeholder="Enter the 12 words separated by spaces here" autocomplete="off" spellcheck="false"></textarea>
      <button id="test-mnemonic-btn" type="submit">Test the secret phrase</button>
    </form>
    <div id="test-mnemonic-result" class="status-message" role="status"></div>
  </section>

  <?php if ($config['anonymous_voter_export'] ?? false): ?>
  <!-- MAPPING KEY SECTION: Separate key for voter identity mapping -->
  <section id="mapping-key-section" class="hidden-section">
    <hr>
    <h2 id="mapping-key">Voter Identity Mapping Key</h2>
    <div class="alert alert-info">
      <strong>Separation of duties:</strong> This is a separate encryption key used only for the voter identity mapping file.
      For maximum privacy, this key should be held by a different person than the one who decrypts vote results.
    </div>
    <p class="muted">When anonymous voter export is enabled, voter emails are stored in a separate encrypted file using this key. This prevents a single person from linking votes to identities.</p>

    <!-- Generate new mapping key -->
    <div id="mapping-init-form" class="hidden-section">
      <button type="button" id="mapping-generate-btn">Generate mapping key</button>
    </div>

    <!-- Display generated mnemonic for confirmation -->
    <div id="mapping-mnemonic-section" class="hidden-section">
      <h3>Mapping secret phrase (12 words)</h3>
      <div id="mapping-mnemonic" class="mnemonic-box"></div>
      <div class="row">
        <label for="mapping-confirm-mnemonic" class="muted">Copy the 12 words to confirm:</label>
        <input type="text" id="mapping-confirm-mnemonic" autocomplete="off">
        <button id="mapping-confirm-btn">Confirm and save mapping key</button>
        <div id="mapping-mnemonic-error" class="err hidden-section"></div>
      </div>
    </div>

    <!-- Display existing mapping key fingerprint -->
    <div id="mapping-pubkey-section" class="hidden-section">
      <h3>Mapping key fingerprint</h3>
      <div>
        <h4>Fingerprint (Base64)</h4>
        <div id="mapping-pubkey-b64" class="key-box"></div>
      </div>
      <div>
        <h4>Fingerprint (Hexadecimal)</h4>
        <div id="mapping-pubkey-hex" class="key-box"></div>
      </div>
      <p class="muted center">Recorded on <span id="mapping-pubkey-timestamp"></span></p>
      <hr>
      <h3>Verify your mapping phrase</h3>
      <p class="muted">Enter the 12-word mapping phrase to verify that it matches the registered key.</p>
      <form id="test-mapping-mnemonic-form">
        <textarea id="test-mapping-mnemonic-input" placeholder="Enter the 12 mapping words here" autocomplete="off" spellcheck="false"></textarea>
        <button id="test-mapping-mnemonic-btn" type="submit">Test the mapping phrase</button>
      </form>
      <div id="test-mapping-mnemonic-result" class="status-message" role="status"></div>
    </div>
  </section>
  <?php else: ?>
  <section id="mapping-key-disabled" class="hidden-section">
    <hr>
    <h2>Voter Identity Mapping Key</h2>
    <p class="muted">Anonymous voter export is not enabled. Enable it in <a href="../vote-settings/">Vote Settings</a> to use a separate mapping key.</p>
  </section>
  <?php endif; ?>

  <div id="result" role="status" aria-live="polite"></div>
  <script type="module" src="init.js"></script>
<?php
$is_admin = true;
// Footer links removed for consistency with admin page navigation
$footer_links = [];
$is_admin_page = true; // Tell footer we don't have flow-stack
include __DIR__ . '/../../footer.php';
?>
