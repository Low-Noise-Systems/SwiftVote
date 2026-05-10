<?php
declare(strict_types=1);

// Load helpers (includes security.php and shared functions like h(), load_admin_emails(), etc.)
require_once __DIR__ . '/../../php-survey-backend/includes/admin_helpers.php';

// Load environment variables for configuration defaults
ensure_env_loaded();

// Load base configuration (no config_loader to avoid setup redirect loop)
$config_path = __DIR__ . '/../../php-survey-backend/config/config.php';
if (!file_exists($config_path)) { http_response_code(500); exit('Critical Error: Configuration file not found.'); }
$config = require $config_path;

// If already configured, send user to the main app
if (is_secret_configured($config)) {
    $base_path = get_base_path($config);
    header('Location: ' . $base_path);
    exit;
}

// Initialize secure session for CSRF protection
init_secure_session();

// Configure error logging
$log_dir = __DIR__ . '/../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

// Shared CSP for setup page
send_default_csp($config, ['allow_quill' => false, 'allow_esm' => false]);

/* ===================== SETUP-SPECIFIC HELPERS ===================== */
// Note: h(), load_admin_emails(), save_admin_emails() come from admin_helpers.php
// Setup uses its own CSRF token key to avoid conflicts with the main app

function setup_csrf_token(): string {
    if (empty($_SESSION['csrf_setup'])) {
        $_SESSION['csrf_setup'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_setup'];
}

function require_setup_csrf(string $token): void {
    if (!isset($_SESSION['csrf_setup']) || !hash_equals($_SESSION['csrf_setup'], $token)) {
        http_response_code(400); exit('Bad CSRF token');
    }
}

function update_env_value(string $path, string $key, string $value): bool {
    $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) {
        $lines = [];
    }
    $found = false;
    $output = [];
    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=/';
    foreach ($lines as $line) {
        if (preg_match($pattern, $line)) {
            $output[] = $key . '=' . $value;
            $found = true;
        } else {
            $output[] = $line;
        }
    }
    if (!$found) {
        if ($output && trim(end($output)) !== '') {
            $output[] = '';
        }
        $output[] = $key . '=' . $value;
    }
    $data = implode("\n", $output);
    if ($data === '' || substr($data, -1) !== "\n") {
        $data .= "\n";
    }
    return file_put_contents($path, $data) !== false;
}

function update_env_values(string $path, array $values): bool {
    foreach ($values as $key => $value) {
        if (!update_env_value($path, $key, $value)) {
            return false;
        }
    }
    return true;
}

function save_app_settings(string $path, array $settings): bool {
    $existing = [];
    if (file_exists($path)) {
        $content = file_get_contents($path);
        if ($content !== false) {
            $existing = json_decode($content, true) ?: [];
        }
    }
    $merged = array_merge($existing, $settings);
    $merged['last_configured_at'] = date('c');
    return file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function extract_host_from_url(string $url): string {
    $parsed = parse_url($url);
    return $parsed['host'] ?? '';
}

function detect_base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Remove /setup/index.php or /setup/ from the path
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $base_path = preg_replace('#/setup(/index\.php)?/?(\?.*)?$#', '', $uri);
    return $protocol . '://' . $host . $base_path;
}

function parse_admin_emails(string $raw): array {
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
    sort($emails);
    return [$emails, $invalid];
}

/* ===================== ROUTER ===================== */
$action = $_POST['action'] ?? 'view';
$flash = null;
$detected_url = detect_base_url();

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_setup_csrf($_POST['csrf'] ?? '');

    // Auto-generate secret key (beginner-friendly: no manual input)
    $secret = bin2hex(random_bytes(32));

    // Get form values
    $app_name = trim((string)($_POST['app_name'] ?? ''));
    $base_url = trim((string)($_POST['base_url'] ?? ''));
    $admin_raw = (string)($_POST['admin_emails'] ?? '');
    [$admin_emails, $invalid_admins] = parse_admin_emails($admin_raw);

    $errors = [];
    if ($app_name === '') {
        $errors[] = 'Please enter an application name.';
    }
    if ($base_url === '') {
        $errors[] = 'Please enter the base URL.';
    } elseif (!filter_var($base_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Please enter a valid URL (e.g., https://vote.example.com).';
    }
    if (empty($admin_emails)) {
        $errors[] = 'Provide at least one valid admin email.';
    }
    if ($invalid_admins) {
        $errors[] = 'Invalid admin emails: ' . implode(', ', $invalid_admins) . '.';
    }

    if ($errors) {
        $flash = ['err', implode(' ', $errors)];
    } else {
        $env_path = __DIR__ . '/../../php-survey-backend/config/.env';
        $admin_path = __DIR__ . '/../../php-survey-backend/config/admin_emails.txt';
        $app_settings_path = __DIR__ . '/../../php-survey-backend/config/app_settings.json';

        // Derive TRUSTED_HOSTS from BASE_URL
        $host = extract_host_from_url($base_url);
        $trusted_hosts = $host;
        // Add www variant if not already www
        if (!str_starts_with($host, 'www.')) {
            $trusted_hosts .= ',www.' . $host;
        }
        // Always allow localhost for development
        $trusted_hosts .= ',localhost';

        // Save to .env: SECRET_KEY, BASE_URL, TRUSTED_HOSTS
        $env_saved = update_env_values($env_path, [
            'SECRET_KEY' => $secret,
            'BASE_URL' => $base_url,
            'TRUSTED_HOSTS' => $trusted_hosts,
        ]);

        // Generate a temporary admin access token (allows first login without email)
        $setup_admin_token = bin2hex(random_bytes(32));

        // Save app_name and setup token to app_settings.json
        $settings_saved = save_app_settings($app_settings_path, [
            'app_name' => $app_name,
            'setup_admin_token' => $setup_admin_token,
            'setup_admin_token_created_at' => date('c'),
        ]);

        // Save admin emails
        $admins_saved = save_admin_emails($admin_path, $admin_emails);

        if ($env_saved && $settings_saved && $admins_saved) {
            putenv('SECRET_KEY=' . $secret);
            // Build the temporary admin access URL
            $admin_access_url = rtrim($base_url, '/') . '/admin/login.php?setup_token=' . $setup_admin_token;
            $_SESSION['setup_admin_url'] = $admin_access_url;
            $flash = ['ok', 'setup_complete'];
        } else {
            $flash = ['err', 'Failed to write config files. Check file permissions.'];
        }
    }
}

/* ===================== VIEW ===================== */
$form_app_name = $_POST['app_name'] ?? '';
$form_base_url = $_POST['base_url'] ?? $detected_url;
$form_admin_emails = $_POST['admin_emails'] ?? implode("\n", load_admin_emails(__DIR__ . '/../../php-survey-backend/config/admin_emails.txt'));
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Initial Setup</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=6">
    <link rel="stylesheet" href="../assets/css/admin.css?v=6">
    <style>
        body.setup-page {
            background:
                radial-gradient(900px 520px at 8% -10%, rgba(224, 0, 15, 0.12), transparent 60%),
                radial-gradient(760px 520px at 92% 0%, rgba(22, 11, 71, 0.08), transparent 65%),
                var(--bg);
        }

        .setup-page .main-wrapper {
            max-width: 1100px;
        }

        .setup-card {
            padding: clamp(28px, 4vw, 56px);
        }

        .setup-hero {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: clamp(18px, 3vw, 28px);
        }

        .setup-eyebrow {
            display: inline-flex;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.18em;
            color: color-mix(in srgb, var(--primary) 70%, var(--muted));
            font-weight: 600;
        }

        .setup-lead {
            margin: 10px 0 0;
            max-width: 36rem;
        }

        .setup-badge {
            background: #fff;
            border: 1px solid rgba(224, 0, 15, 0.18);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            box-shadow: 0 10px 20px rgba(224, 0, 15, 0.12);
        }

        .setup-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr);
            gap: clamp(24px, 4vw, 48px);
            align-items: start;
        }

        .setup-aside {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .setup-info {
            background: var(--bg-accent);
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 18px;
            padding: clamp(18px, 2.6vw, 26px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
        }

        .setup-subtitle {
            margin: 0 0 8px;
            font-size: 1.1rem;
        }

        .setup-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .setup-step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.06);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
        }

        .setup-step-index {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.9rem;
            letter-spacing: 0.08em;
        }

        .setup-step strong {
            display: block;
            font-weight: 650;
            color: var(--text);
        }

        .setup-step .muted {
            display: block;
            font-size: 0.9rem;
        }

        .setup-panel {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 20px;
            padding: clamp(20px, 3vw, 32px);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        .setup-form.admin-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .setup-form .row {
            max-width: 100%;
        }

        .setup-field-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .setup-save-btn {
            align-self: flex-start;
            padding: 14px 26px;
            border-radius: 999px;
            font-weight: 600;
            background: linear-gradient(120deg, #e0000f, #7f0000);
            box-shadow: 0 16px 30px rgba(224, 0, 15, 0.25);
        }

        .setup-next {
            margin-top: 16px;
        }

        @media (max-width: 980px) {
            .setup-grid {
                grid-template-columns: 1fr;
            }

            .setup-badge {
                align-self: flex-start;
            }
        }

        @media (max-width: 640px) {
            .setup-hero {
                flex-direction: column;
                align-items: flex-start;
            }

            .setup-field-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .setup-save-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body class="setup-page">
<div class="main-wrapper">
    <div class="card setup-card">
        <div class="setup-hero">
            <div class="setup-hero-text">
                <span class="setup-eyebrow">Welcome</span>
                <h1>Initial Setup</h1>
                <p class="setup-lead">
                    Let's configure your application. This only takes a moment.
                </p>
            </div>
            <div class="setup-badge">Quick Setup</div>
        </div>

        <?php if ($flash): ?>
            <?php [$type, $msg] = $flash; ?>
            <?php if ($msg === 'setup_complete' && isset($_SESSION['setup_admin_url'])): ?>
                <div class="flash ok">
                    <strong>Setup complete!</strong><br><br>
                    Since email is not yet configured, use this temporary link to access the admin panel:<br>
                    <a href="<?= h($_SESSION['setup_admin_url']) ?>" style="word-break: break-all;"><?= h($_SESSION['setup_admin_url']) ?></a><br><br>
                    <small>Save this link! You can disable it from the admin panel once email is configured.</small>
                </div>
                <?php unset($_SESSION['setup_admin_url']); ?>
            <?php else: ?>
                <div class="flash <?= $type === 'ok' ? 'ok' : 'err' ?>"><?= $type === 'ok' ? $msg : h($msg) ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="setup-grid">
            <div class="setup-aside">
                <div class="setup-info">
                    <h2 class="setup-subtitle">Getting Started</h2>
                    <p class="muted">
                        You only need to do this once. Fill in the details below to configure your election or survey application.
                    </p>
                </div>

                <div class="setup-steps">
                    <div class="setup-step">
                        <span class="setup-step-index">01</span>
                        <div>
                            <strong>Name your app</strong>
                            <span class="muted">Choose a name that voters will see.</span>
                        </div>
                    </div>
                    <div class="setup-step">
                        <span class="setup-step-index">02</span>
                        <div>
                            <strong>Confirm the web address</strong>
                            <span class="muted">We'll auto-detect this for you.</span>
                        </div>
                    </div>
                    <div class="setup-step">
                        <span class="setup-step-index">03</span>
                        <div>
                            <strong>Add administrators</strong>
                            <span class="muted">Enter email addresses for admin access.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="setup-panel">
                <form method="post" class="row admin-form setup-form" id="setup-form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="csrf" value="<?= h(setup_csrf_token()) ?>">

                    <label class="row">
                        <span class="muted">Application name</span>
                        <input type="text" name="app_name" value="<?= h($form_app_name) ?>" required placeholder="e.g., La French Tech Community Board election">
                        <small class="muted">This name will appear in the header and in emails sent to voters.</small>
                    </label>

                    <label class="row">
                        <span class="muted">Web address (URL)</span>
                        <input type="url" name="base_url" value="<?= h($form_base_url) ?>" required placeholder="https://vote.example.com">
                        <small class="muted">The full URL where this application is hosted. Used for email links.</small>
                    </label>

                    <label class="row">
                        <span class="muted">Administrator emails (one per line)</span>
                        <textarea name="admin_emails" rows="4" required placeholder="admin@example.com"><?= h($form_admin_emails) ?></textarea>
                        <small class="muted">These people will have full access to manage the election. Add at least one.</small>
                    </label>

                    <button type="submit" class="setup-save-btn">Complete Setup</button>
                </form>

                <p class="admin-form-note setup-next">
                    <strong>What's next?</strong> After setup, you can configure email settings, add candidates, and invite voters from the admin panel.
                </p>
            </div>
        </div>
    </div>
</div>
</body>
</html>
