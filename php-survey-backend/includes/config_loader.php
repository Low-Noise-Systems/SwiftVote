<?php
// Load environment variables from .env file
if (file_exists(__DIR__ . '/../config/.env')) {
    $lines = file(__DIR__ . '/../config/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || $line === '') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key !== '') {
            putenv($key . '=' . $value);
        }
    }
}

/* ===================== LOAD CONFIG ===================== */
$config_path = __DIR__ . '/../config/config.php';
if (!file_exists($config_path)) { http_response_code(500); exit('Critical Error: Configuration file not found.'); }
$config = require $config_path;

/* ======== SECURITY: TRUSTED HOSTS + FIXED BASE URL ======== */
$trusted = $config['trusted_hosts'] ?? [];
$host = $_SERVER['HTTP_HOST'] ?? '';
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$setup_path = function_exists('get_setup_path') ? get_setup_path($config) : '/setup';
$is_setup_request = is_string($request_path) && $request_path !== '' && str_starts_with($request_path, $setup_path);

// Check if app is configured (SECRET_KEY is valid)
// If not configured, bypass trusted hosts to allow setup access
$is_app_configured = function_exists('is_secret_configured')
    ? is_secret_configured($config)
    : (strlen($config['secret_key'] ?? '') >= 32 && stripos($config['secret_key'] ?? '', 'change-me') === false);

// Only enforce trusted hosts if: app is configured AND not a setup request
if ($trusted && $is_app_configured && !$is_setup_request && !in_array($host, $trusted, true)) {
    http_response_code(400);
    exit('Unauthorized host');
}

// In debug mode on localhost, use the current host as base_url to avoid redirecting to production
$debug_mode = getenv('DEBUG_MODE') === 'true';
$is_local_host = (bool)preg_match('/^(localhost|127\\.0\\.0\\.1)(:\\d+)?$/', $host);
if ($debug_mode && $is_local_host) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_parts = parse_url($config['base_url']);
    $path = $base_parts['path'] ?? '';
    $config['base_url'] = $scheme . '://' . $host . $path;
}
$base_url = $config['base_url'] ?: (function(){ http_response_code(500); exit('base_url not configured'); })();

/* ======== SECRET GUARD ======== */
if (function_exists('require_configured_secret')) {
    require_configured_secret($config);
} else {
    if (strlen($config['secret_key']) < 32 || str_contains($config['secret_key'], 'CHANGE-ME')) {
        http_response_code(500); exit('Critical Error: secret_key not configured.');
    }
}

/* ======== ENSURE DATA DIR ======== */
if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0750, true) && !is_dir($config['data_dir'])) {
    http_response_code(500); exit('Critical Error: Unable to create data directory.');
}

$vote_dirs = [$config['votes_dir'] ?? null];
foreach ($vote_dirs as $dir) {
    if (!$dir) continue;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        http_response_code(500); exit('Critical Error: Unable to create vote storage directory.');
    }
}

$admin_emails_path = __DIR__ . '/../config/admin_emails.txt';

$admin_allowed = function(string $email) use ($admin_emails_path): bool {
    if (!file_exists($admin_emails_path)) return false;
    $lines = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (strtolower(trim($line)) === strtolower($email)) return true;
    }
    return false;
};

$is_admin = false;
if (isset($_SESSION['admin_email']) && $admin_allowed($_SESSION['admin_email'])) {
    $is_admin = true;
}

// Send a shared CSP for all pages that load the config (skips CLI contexts)
send_default_csp($config);

return $config;
?>
