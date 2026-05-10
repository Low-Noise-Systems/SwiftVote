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

// Shared CSP for admin console
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

if ($action === 'disable_setup_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf'] ?? '');

    // Load existing settings and remove the setup token
    $existing = load_app_settings($settings_file, $settings_defaults);
    unset($existing['setup_admin_token']);
    unset($existing['setup_admin_token_created_at']);
    $existing['setup_token_disabled_at'] = date('Y-m-d H:i:s');

    if (save_json_file($settings_file, $existing)) {
        // Also clear the session flag if present
        unset($_SESSION['admin_via_setup_token']);
        $_SESSION['flash'] = ['ok', 'Temporary admin access has been disabled. You will need to use email verification to log in.'];
    } else {
        $_SESSION['flash'] = ['err', 'Error disabling temporary access.'];
    }
    header('Location: index.php'); exit;
}

if ($action === 'update_security_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf'] ?? '');

    $turnstile_enabled = isset($_POST['turnstile_enabled']) && $_POST['turnstile_enabled'] === '1';
    $turnstile_site_key = trim((string)($_POST['turnstile_site_key'] ?? ''));
    $turnstile_secret_key = trim((string)($_POST['turnstile_secret_key'] ?? ''));

    $errors = [];

    // If Turnstile is enabled, require both keys
    if ($turnstile_enabled) {
        if ($turnstile_site_key === '' || $turnstile_site_key === 'your_turnstile_site_key_here') {
            $errors[] = 'Turnstile Site Key is required when captcha is enabled.';
        }
        if ($turnstile_secret_key === '' || $turnstile_secret_key === 'your_turnstile_secret_key_here') {
            $errors[] = 'Turnstile Secret Key is required when captcha is enabled.';
        }
    }

    if ($errors) {
        $_SESSION['flash'] = ['err', implode(' ', $errors)];
        header('Location: index.php'); exit;
    }

    // Load existing settings and merge
    $existing = load_app_settings($settings_file, $settings_defaults);
    $new_settings = array_merge($existing, [
        'turnstile_enabled' => $turnstile_enabled,
        'turnstile_site_key' => $turnstile_site_key,
        'turnstile_secret_key' => $turnstile_secret_key,
        'last_configured_at' => date('Y-m-d H:i:s'),
    ]);

    if (save_json_file($settings_file, $new_settings)) {
        $_SESSION['flash'] = ['ok', 'Security settings updated.'];
    } else {
        $_SESSION['flash'] = ['err', 'Error updating security settings.'];
    }
    header('Location: index.php'); exit;
}

/* ===================== RENDER ===================== */
admin_header_html('Security Settings', $config['app_name'], $config, '../../assets/css/style.css?v=6', '../../assets/css/admin.css?v=6');
admin_flash_html($flash);

$turnstile_enabled = !empty($settings['turnstile_enabled']);
$turnstile_site_key = $settings['turnstile_site_key'] ?? '';
$turnstile_secret_key = $settings['turnstile_secret_key'] ?? '';

// Check if setup token is still active
$setup_token_active = !empty($settings['setup_admin_token']);
$setup_token_created = $settings['setup_admin_token_created_at'] ?? null;

// Mask the secret key for display
$masked_secret = '';
if ($turnstile_secret_key !== '' && $turnstile_secret_key !== 'your_turnstile_secret_key_here') {
    $masked_secret = substr($turnstile_secret_key, 0, 8) . str_repeat('*', max(0, strlen($turnstile_secret_key) - 12)) . substr($turnstile_secret_key, -4);
}
?>

<div class="back-link">
    <a href="../index.php">Back to Admin Panel</a>
</div>

<?php if ($setup_token_active): ?>
<div class="admin-alert warning" style="margin-bottom: 1.5rem;">
    <strong>Temporary Admin Access is Active</strong><br>
    <p style="margin: 0.5rem 0;">
        A setup token is currently active, allowing admin access without email verification.
        This was created during initial setup to let you configure email settings.
    </p>
    <?php if ($setup_token_created): ?>
    <p style="margin: 0.5rem 0; font-size: 0.9em;">
        Created: <?= h($setup_token_created) ?>
    </p>
    <?php endif; ?>
    <p style="margin: 0.5rem 0;">
        <strong>Once you have configured email and verified it works, disable this temporary access for security.</strong>
    </p>
    <form method="post" style="margin-top: 1rem;">
        <input type="hidden" name="action" value="disable_setup_token">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button type="submit" style="background: #dc2626; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer; font-weight: 600;" onclick="return confirm('Are you sure? You will need to use email verification to log in after this.');">
            Disable Temporary Access
        </button>
    </form>
</div>
<?php endif; ?>

<div class="admin-info-box">
    <strong>Current configuration:</strong>
    <div class="info-item">
        <span class="info-label">Captcha (Turnstile):</span>
        <span class="info-value"><?= $turnstile_enabled ? 'Enabled' : 'Disabled' ?></span>
    </div>
    <?php if ($turnstile_enabled): ?>
    <div class="info-item">
        <span class="info-label">Site Key:</span>
        <span class="info-value"><?= h($turnstile_site_key) ?></span>
    </div>
    <div class="info-item">
        <span class="info-label">Secret Key:</span>
        <span class="info-value"><?= h($masked_secret) ?></span>
    </div>
    <?php endif; ?>
</div>

<h2 class="admin-section-header">Cloudflare Turnstile (Captcha)</h2>

<p class="muted" style="margin-bottom: 1rem;">
    Turnstile is a free captcha service from Cloudflare that helps protect your forms from bots.
    <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Get your keys from Cloudflare Dashboard</a>.
</p>

<form method="post" class="row admin-form" id="security-settings-form">
    <input type="hidden" name="action" value="update_security_settings">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <label class="row checkbox-row">
        <input type="hidden" name="turnstile_enabled" value="0">
        <input type="checkbox" name="turnstile_enabled" value="1" <?= $turnstile_enabled ? 'checked' : '' ?>>
        <span>Enable Turnstile captcha on login and verification forms</span>
    </label>

    <label class="row">
        <span class="muted">Turnstile Site Key</span>
        <input type="text" name="turnstile_site_key" value="<?= h($turnstile_site_key) ?>" placeholder="0x4AAAAAAA...">
        <small class="muted">The public site key from your Cloudflare Turnstile widget.</small>
    </label>

    <label class="row">
        <span class="muted">Turnstile Secret Key</span>
        <input type="password" name="turnstile_secret_key" value="<?= h($turnstile_secret_key) ?>" placeholder="0x4AAAAAAA..." autocomplete="new-password">
        <small class="muted">The secret key from your Cloudflare Turnstile widget. Keep this private.</small>
    </label>

    <button type="submit" id="submit-security-settings-btn">
        Save security settings
    </button>
</form>

<p class="admin-form-note">
    <strong>Note:</strong> When Turnstile is disabled, forms will work without captcha verification.
    This is useful for testing or if you don't want to use Cloudflare's service.
</p>
