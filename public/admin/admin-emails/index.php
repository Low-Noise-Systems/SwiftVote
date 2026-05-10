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
$admin_emails_path = __DIR__ . '/../../../php-survey-backend/config/admin_emails.txt';
$action = $_POST['action'] ?? 'view';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

if ($action === 'disconnect') {
    require_csrf($_POST['csrf'] ?? '');
    unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
    $_SESSION['flash'] = ['ok', 'Disconnected.'];
    header('Location: ' . $base_url); exit;
}

if ($action === 'save_admin_emails' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf'] ?? '');

    $raw = (string)($_POST['admin_emails'] ?? '');
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $emails = [];
    $invalid = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!filter_var($line, FILTER_VALIDATE_EMAIL)) {
            $invalid[] = $line;
            continue;
        }
        $emails[] = strtolower($line);
    }

    $emails = array_values(array_unique($emails));
    if (empty($emails)) {
        $_SESSION['flash'] = ['err', 'Provide at least one valid admin email.'];
        header('Location: index.php'); exit;
    }
    if ($invalid) {
        $_SESSION['flash'] = ['err', 'Invalid emails: ' . implode(', ', $invalid)];
        header('Location: index.php'); exit;
    }

    if (save_admin_emails($admin_emails_path, $emails)) {
        $_SESSION['flash'] = ['ok', 'Admin email list updated.'];
    } else {
        $_SESSION['flash'] = ['err', 'Error saving admin emails.'];
    }
    header('Location: index.php'); exit;
}

$admin_emails = load_admin_emails($admin_emails_path);
$admin_emails_text = implode("\n", $admin_emails);

/* ===================== RENDER ===================== */
admin_header_html('Admin Emails', $config['app_name'], $config, '../../assets/css/style.css?v=6', '../../assets/css/admin.css?v=6');
admin_flash_html($flash);
?>

<div class="back-link">
    <a href="../index.php">Back to Admin Panel</a>
</div>

<div class="admin-info-box">
    <strong>Current configuration:</strong>
    <div class="info-item">
        <span class="info-label">Admin count:</span>
        <span class="info-value"><?= count($admin_emails) ?></span>
    </div>
    <div class="info-item">
        <span class="info-label">Config file:</span>
        <span class="info-value"><?= h($admin_emails_path) ?></span>
    </div>
</div>

<h2 class="admin-section-header">Admin Access</h2>

<form method="post" class="row admin-form" id="admin-emails-form">
    <input type="hidden" name="action" value="save_admin_emails">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <label class="row">
        <span class="muted">Admin emails (one per line)</span>
        <textarea name="admin_emails" rows="8" required><?= h($admin_emails_text) ?></textarea>
        <small class="muted">Only the emails listed here can access the admin panel.</small>
    </label>

    <button type="submit" class="admin-toggle-btn open">Save admin list</button>
</form>
