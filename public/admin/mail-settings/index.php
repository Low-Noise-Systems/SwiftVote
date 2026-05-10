<?php
declare(strict_types=1);

// Load admin helpers (includes security.php, helpers.php, and admin-specific functions)
require_once __DIR__ . '/../../../php-survey-backend/includes/admin_helpers.php';

// Initialize secure session
init_secure_session();

// Configure error logging and rotate logs
$log_dir = __DIR__ . '/../../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

/* ===================== LOAD CONFIG ===================== */
$config_path = __DIR__ . '/../../../php-survey-backend/config/config.php';
if (!file_exists($config_path)) { http_response_code(500); exit('Critical Error: Configuration file not found.'); }
$config = require $config_path;

/* ======== SECURITY: TRUSTED HOSTS + FIXED BASE URL ======== */
$trusted = $config['trusted_hosts'] ?? [];
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($trusted && !in_array($host, $trusted, true)) { http_response_code(400); exit('Unauthorized host'); }
$base_url = resolve_base_url($config);

/* ===================== SECURITY: ADMIN GUARD (STRICT) ===================== */
require_once __DIR__ . '/../../../php-survey-backend/includes/admin_guard_strict.php';

/* ======== SECRET GUARD ======== */
require_configured_secret($config);

/* ======== ENSURE DATA DIR ======== */
if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0750, true) && !is_dir($config['data_dir'])) {
    http_response_code(500); exit('Critical Error: Unable to create data directory.');
}

// Shared CSP for admin console (allows Turnstile + Quill/CDN assets)
send_default_csp($config);

/* ===================== ROUTER ===================== */
$settings_file = $config['app_settings_json'] ?? (__DIR__ . '/../../../php-survey-backend/config/app_settings.json');
$settings_defaults = default_app_settings($config);
$settings = load_app_settings($settings_file, $settings_defaults);

$action = $_POST['action'] ?? 'view';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

if ($action === 'disconnect') {
    require_csrf($_POST['csrf'] ?? '');
    unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
    $_SESSION['flash'] = ['ok', 'Disconnected.'];
    header('Location: ' . $base_url); exit;
}

if ($action === 'update_mail_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf'] ?? '');

    $app_name = trim((string)($_POST['app_name'] ?? ''));
    $mail_mode = (string)($_POST['mail_mode'] ?? 'hosting');
    $from_address = trim((string)($_POST['hosting_from_address'] ?? ''));
    $from_name = trim((string)($_POST['hosting_from_name'] ?? ''));
    $reply_to_address = trim((string)($_POST['hosting_reply_to_address'] ?? ''));
    $reply_to_name = trim((string)($_POST['hosting_reply_to_name'] ?? ''));

    $errors = [];
    if ($app_name === '') {
        $errors[] = 'Application name cannot be empty.';
    }
    if ($mail_mode !== 'hosting' && $mail_mode !== 'smtp') {
        $errors[] = 'Invalid mail mode.';
    }
    if (!filter_var($from_address, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'From address must be a valid email.';
    }
    if ($reply_to_address !== '' && !filter_var($reply_to_address, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Reply-to address must be a valid email or left blank.';
    }

    if ($errors) {
        $_SESSION['flash'] = ['err', implode(' ', $errors)];
        header('Location: index.php'); exit;
    }

    $new_settings = [
        'app_name' => $app_name,
        'mail_mode' => $mail_mode,
        'hosting_from_address' => $from_address,
        'hosting_from_name' => $from_name,
        'hosting_reply_to_address' => $reply_to_address,
        'hosting_reply_to_name' => $reply_to_name,
        'last_configured_at' => date('Y-m-d H:i:s'),
    ];

    if (save_json_file($settings_file, $new_settings)) {
        $warning = '';
        if ($mail_mode === 'smtp') {
            $smtp_file = $config['smtp_mailboxes_file'] ?? '';
            if ($smtp_file === '' || !file_exists($smtp_file)) {
                $warning = ' Saved, but smtp_mailboxes.json is missing.';
            } elseif (!is_readable($smtp_file)) {
                $warning = ' Saved, but smtp_mailboxes.json is not readable.';
            }
        }
        $_SESSION['flash'] = ['ok', 'Mail settings updated.' . $warning];
    } else {
        $_SESSION['flash'] = ['err', 'Error updating mail settings.'];
    }
    header('Location: index.php'); exit;
}

/* ===================== RENDER ===================== */
admin_header_html('Mail Settings', $config['app_name'], $config, '../../assets/css/style.css?v=6', '../../assets/css/admin.css?v=6');
admin_flash_html($flash);

$smtp_file = $config['smtp_mailboxes_file'] ?? '';
$smtp_missing = ($smtp_file === '' || !file_exists($smtp_file));
$smtp_unreadable = (!$smtp_missing && !is_readable($smtp_file));
?>

<div class="back-link">
    <a href="../index.php">Back to Admin Panel</a>
</div>

<div class="admin-info-box">
    <strong>Current configuration:</strong>
    <div class="info-item">
        <span class="info-label">App name:</span>
        <span class="info-value"><?= h($settings['app_name']) ?></span>
    </div>
    <div class="info-item">
        <span class="info-label">Mail mode:</span>
        <span class="info-value"><?= h($settings['mail_mode']) ?></span>
    </div>
    <div class="info-item">
        <span class="info-label">From address:</span>
        <span class="info-value"><?= h($settings['hosting_from_address']) ?></span>
    </div>
    <div class="info-item">
        <span class="info-label">From name:</span>
        <span class="info-value"><?= h($settings['hosting_from_name']) ?></span>
    </div>
    <div class="info-item">
        <span class="info-label">Reply-to address:</span>
        <span class="info-value"><?= h($settings['hosting_reply_to_address'] !== '' ? $settings['hosting_reply_to_address'] : $settings['hosting_from_address']) ?></span>
    </div>
    <div class="info-item">
        <span class="info-label">Reply-to name:</span>
        <span class="info-value"><?= h($settings['hosting_reply_to_name'] !== '' ? $settings['hosting_reply_to_name'] : $settings['hosting_from_name']) ?></span>
    </div>
</div>

<h2 class="admin-section-header">Mail Settings</h2>

<?php if ($settings['mail_mode'] === 'smtp' && ($smtp_missing || $smtp_unreadable)): ?>
    <div class="admin-alert warning">
        <strong>SMTP configuration missing</strong><br>
        Mail mode is set to SMTP but the configuration file is unavailable.
        <?php if ($smtp_file): ?>
            Check <strong><?= h($smtp_file) ?></strong>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" class="row admin-form" id="mail-settings-form">
    <input type="hidden" name="action" value="update_mail_settings">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <label class="row">
        <span class="muted">Application name</span>
        <input type="text" name="app_name" value="<?= h($settings['app_name']) ?>" maxlength="120" required>
        <small class="muted">Shown in page headers and email templates.</small>
    </label>

    <label class="row">
        <span class="muted">Mail sending mode</span>
        <select name="mail_mode">
            <option value="hosting" <?= $settings['mail_mode'] === 'hosting' ? 'selected' : '' ?>>Hosting (PHP mail)</option>
            <option value="smtp" <?= $settings['mail_mode'] === 'smtp' ? 'selected' : '' ?>>SMTP (smtp_mailboxes.json)</option>
        </select>
        <small class="muted">SMTP credentials are configured in <?= h($smtp_file ?: 'php-survey-backend/config/smtp_mailboxes.json') ?>.</small>
    </label>

    <label class="row">
        <span class="muted">From address (hosting mode)</span>
        <input type="email" name="hosting_from_address" value="<?= h($settings['hosting_from_address']) ?>" required>
    </label>

    <label class="row">
        <span class="muted">From name (hosting mode)</span>
        <input type="text" name="hosting_from_name" value="<?= h($settings['hosting_from_name']) ?>" maxlength="120">
    </label>

    <label class="row">
        <span class="muted">Reply-to address (optional)</span>
        <input type="email" name="hosting_reply_to_address" value="<?= h($settings['hosting_reply_to_address']) ?>">
        <small class="muted">Leave blank to use the From address.</small>
    </label>

    <label class="row">
        <span class="muted">Reply-to name (optional)</span>
        <input type="text" name="hosting_reply_to_name" value="<?= h($settings['hosting_reply_to_name']) ?>" maxlength="120">
    </label>

    <button type="submit" id="submit-mail-settings-btn">
        Save mail settings
    </button>
</form>

<p class="admin-form-note">
    <strong>Note:</strong> Hosting mode uses the sender fields above. SMTP mode ignores them and uses settings from smtp_mailboxes.json.
</p>
