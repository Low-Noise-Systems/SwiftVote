<?php
/**
 * Security Functions
 *
 * Centralized security functions including:
 * - Content Security Policy (CSP) helper
 * - Session management (secure initialization and timeout)
 * - Authentication guards (admin and voter)
 * - Log management (rotation and cleanup)
 * - Turnstile verification
 */
declare(strict_types=1);

/* ===================== SECURITY HEADERS ===================== */

// Per-request CSP nonce for inline scripts/styles
function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = bin2hex(random_bytes(16));
    }
    return $nonce;
}

function csp_nonce_attr(): string {
    return ' nonce="' . htmlspecialchars(csp_nonce(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
}

/**
 * Build a CSP policy string that covers public + admin surfaces, including
 * Turnstile, Quill (admin rich text), and the vote-key ESM tool.
 * Uses a nonce for inline scripts; no script unsafe-inline.
 */
function build_csp_policy(array $config, array $options = []): string {
    $allow_quill  = $options['allow_quill']  ?? true; // CDN Quill assets for admin editors
    $allow_esm    = $options['allow_esm']    ?? true; // vote-key page uses esm.sh modules

    $script_src = ["'self'"];
    $style_src  = ["'self'", "'unsafe-inline'"]; // keep inline styles for now
    $connect_src = ["'self'"];
    $frame_src = ["'self'"];
    $img_src = ["'self'", "data:"];
    $font_src = ["'self'", "data:"];
    $default_src = ["'self'"];
    $form_action = ["'self'"];
    $frame_ancestors = ["'self'"];

    // Allow nonce-based inline scripts
    $script_src[] = "'nonce-" . csp_nonce() . "'";

    if (!empty($config['turnstile_site_key'])) {
        $script_src[] = 'https://challenges.cloudflare.com';
        $frame_src[] = 'https://challenges.cloudflare.com';
        $connect_src[] = 'https://challenges.cloudflare.com';
    }

    if ($allow_quill) {
        $script_src[] = 'https://cdn.quilljs.com';
        $style_src[] = 'https://cdn.quilljs.com';
        $script_src[] = 'https://cdn.jsdelivr.net';
        $style_src[] = 'https://cdn.jsdelivr.net';
    }

    if ($allow_esm) {
        $script_src[] = 'https://esm.sh';
        $connect_src[] = 'https://esm.sh';
        // vote-key uses esm.sh but falls back to jsdelivr for some builds
        $script_src[] = 'https://cdn.jsdelivr.net';
        $connect_src[] = 'https://cdn.jsdelivr.net';
        // allow WebAssembly eval path required by libsodium-wrappers
        $script_src[] = "'wasm-unsafe-eval'";
        $script_src[] = "'unsafe-eval'";
    }

    $directives = [
        'default-src'      => $default_src,
        'script-src'       => array_values(array_unique($script_src)),
        'style-src'        => array_values(array_unique($style_src)),
        'img-src'          => array_values(array_unique($img_src)),
        'font-src'         => array_values(array_unique($font_src)),
        'connect-src'      => array_values(array_unique($connect_src)),
        'frame-src'        => array_values(array_unique($frame_src)),
        'form-action'      => array_values(array_unique($form_action)),
        'frame-ancestors'  => array_values(array_unique($frame_ancestors)),
        'object-src'       => ["'none'"],
        'base-uri'         => ["'self'"],
        'manifest-src'     => ["'self'"],
        'media-src'        => ["'self'"],
    ];

    $parts = [];
    foreach ($directives as $name => $values) {
        if (empty($values)) continue;
        $parts[] = $name . ' ' . implode(' ', $values);
    }

    // Upgrade mixed content by default
    $parts[] = 'upgrade-insecure-requests';

    return implode('; ', $parts);
}

/**
 * Send the CSP header once per request.
 */
function send_default_csp(array $config, array $options = []): void {
    static $sent = false;
    if ($sent || headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    $policy = build_csp_policy($config, $options);
    header('Content-Security-Policy: ' . $policy);
    $sent = true;
}

/**
 * Resolve the base URL, substituting localhost when DEBUG_MODE is enabled.
 */
function resolve_base_url(array $config): string {
    $base = $config['base_url'] ?? '';
    if ($base === '') {
        http_response_code(500);
        exit('base_url not configured');
    }

    $debug_mode = getenv('DEBUG_MODE') === 'true';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $is_local_host = (bool)preg_match('/^(localhost|127\\.0\\.0\\.1)(:\\d+)?$/', $host);

    if ($debug_mode && $is_local_host) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $parts = parse_url($base);
        $path = $parts['path'] ?? '';
        return $scheme . '://' . $host . $path;
    }

    return $base;
}

/* ===================== INITIAL SETUP HELPERS ===================== */

function is_secret_configured(array $config): bool {
    $secret = (string)($config['secret_key'] ?? '');
    if (strlen($secret) < 32) {
        return false;
    }
    if (stripos($secret, 'change-me') !== false) {
        return false;
    }
    return true;
}

function get_base_path(array $config): string {
    $base = (string)($config['base_url'] ?? '');
    if ($base === '') {
        return '/';
    }
    $path = parse_url($base, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    if (str_ends_with($path, 'index.php')) {
        $path = substr($path, 0, -strlen('index.php'));
    }
    if ($path === '') {
        return '/';
    }
    return rtrim($path, '/') . '/';
}

function get_setup_path(array $config): string {
    $base = get_base_path($config);
    $base = rtrim($base, '/');
    if ($base === '') {
        return '/setup';
    }
    return $base . '/setup';
}

function require_configured_secret(array $config): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (is_secret_configured($config)) {
        return;
    }
    $setup_path = get_setup_path($config);
    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (is_string($request_path) && $request_path !== '' && str_starts_with($request_path, $setup_path)) {
        return;
    }
    header('Location: ' . $setup_path . '/');
    exit;
}

/* ===================== SESSION MANAGEMENT ===================== */

/**
 * Load .env once so session settings (timeout, debug) are available
 */
function ensure_env_loaded(): void {
    static $env_loaded = false;
    if ($env_loaded) {
        return;
    }

    $env_path = __DIR__ . '/../config/.env';
    if (is_readable($env_path)) {
        $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key !== '') {
                putenv($key . '=' . $value);
            }
        }
    }

    $env_loaded = true;
}

/**
 * Resolve session idle timeout from env (SESSION_IDLE_TIMEOUT or DEBUG_MODE)
 * Defaults to 30 minutes in production, 2 hours in debug
 */
function get_session_timeout_seconds(): int {
    ensure_env_loaded();

    $env_timeout = getenv('SESSION_IDLE_TIMEOUT') ?: getenv('SESSION_IDLE_TIMEOUT_SECONDS');
    if (is_string($env_timeout) && $env_timeout !== '') {
        $timeout = (int)$env_timeout;
        if ($timeout > 0) {
            return max(60, $timeout);
        }
    }

    $debug_mode = getenv('DEBUG_MODE') === 'true';
    return $debug_mode ? 7200 : 1800;
}

/**
 * Human friendly label for timeout (used in flash message)
 */
function format_session_timeout_label(int $seconds): string {
    if ($seconds % 60 === 0) {
        $minutes = (int)($seconds / 60);
        return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
    }

    return "{$seconds} seconds";
}

/**
 * Initialize session with secure parameters and enforce timeout
 * Ensures session cookies are secure and properly configured
 * Automatically enforces session idle timeout (2 hours debug, 30 minutes production by default)
 */
function init_secure_session(): void {
    ensure_env_loaded();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => true  // SECURITY: Only send cookies over HTTPS
        ]);
        session_start();
    }

    // Automatically enforce session timeout after session is started
    $session_timeout = get_session_timeout_seconds();
    enforce_session_idle_timeout($session_timeout);
}

/**
 * Enforce session idle timeout
 * Logs out both admin and voter sessions if inactive for specified duration
 *
 * @param int $timeoutSeconds Timeout in seconds (default: 1800 = 30 minutes)
 */
function enforce_session_idle_timeout(int $timeoutSeconds = 1800): void {
    if ($timeoutSeconds <= 0) {
        return;
    }

    $now = time();
    $last = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : 0;

    // Check timeout for both admin and voter sessions (separate authentication)
    $session_expired = false;
    if ($last > 0 && ($now - $last) >= $timeoutSeconds) {
        if (isset($_SESSION['admin_email'])) {
            unset($_SESSION['admin_email']);
            $session_expired = true;
        }
        if (isset($_SESSION['voter_email'])) {
            unset($_SESSION['voter_email']);
            $session_expired = true;
        }
        if ($session_expired && empty($_SESSION['flash'])) {
            $duration = format_session_timeout_label($timeoutSeconds);
            $_SESSION['flash'] = ['err', "Session expired after {$duration} of inactivity."];
        }
    }

    $_SESSION['last_activity'] = $now;
}

/* ===================== LOG MANAGEMENT ===================== */

/**
 * Configure error logging to secure location
 *
 * @param string $log_dir Path to log directory
 */
function setup_error_logging(string $log_dir): void {
    ini_set('error_log', $log_dir . '/error_log');
}

/**
 * Rotate and compress old log files
 * - Daily rotation of main error log
 * - Compression of logs older than 7 days
 * - Deletion of compressed logs older than 30 days
 *
 * @param string $log_dir Path to log directory
 */
function rotate_logs(string $log_dir): void {
    if (!is_dir($log_dir)) {
        error_log('LOG ROTATE: Log directory missing: ' . $log_dir);
        return;
    }
    if (!is_writable($log_dir)) {
        error_log('LOG ROTATE: Log directory not writable: ' . $log_dir);
        return;
    }

    $now = time();
    $check_file = $log_dir . '/last_check';
    if (!should_run_log_maintenance($check_file, $now)) {
        return;
    }

    $success = true;
    if (!rotate_daily_log($log_dir, $now)) {
        $success = false;
    }
    if (!compress_old_logs($log_dir, $now)) {
        $success = false;
    }
    if (!purge_old_archives($log_dir, $now)) {
        $success = false;
    }

    if ($success && @touch($check_file) === false) {
        error_log('LOG ROTATE: Unable to update last_check marker');
    }
}

function should_run_log_maintenance(string $check_file, int $now): bool {
    if (!file_exists($check_file)) {
        return true;
    }
    $last_check = filemtime($check_file);
    if ($last_check === false) {
        error_log('LOG ROTATE: Unable to read last_check timestamp');
        return true;
    }
    return $last_check < $now - 3600;
}

function rotate_daily_log(string $log_dir, int $now): bool {
    $log_file = $log_dir . '/error_log';
    if (!file_exists($log_file)) {
        return true;
    }
    $log_mtime = filemtime($log_file);
    if ($log_mtime === false) {
        error_log('LOG ROTATE: Unable to read log mtime');
        return false;
    }
    if (date('Y-m-d', $log_mtime) === date('Y-m-d', $now)) {
        return true;
    }
    $target = $log_dir . '/error_log.' . date('Y-m-d', $log_mtime);
    if (!rename($log_file, $target)) {
        error_log('LOG ROTATE: Unable to rotate log to ' . $target);
        return false;
    }
    return true;
}

function is_valid_rotated_log(string $basename): bool {
    return preg_match('/^error_log\.\d{4}-\d{2}-\d{2}$/', $basename) === 1;
}

function compress_old_logs(string $log_dir, int $now): bool {
    $files = glob($log_dir . '/error_log.*');
    if ($files === false) {
        error_log('LOG ROTATE: Unable to list log files for compression');
        return false;
    }

    $ok = true;
    foreach ($files as $file) {
        if (!is_file($file) || preg_match('/\.gz$/', $file)) {
            continue;
        }
        $mtime = filemtime($file);
        if ($mtime === false) {
            error_log('LOG ROTATE: Unable to read mtime for ' . $file);
            $ok = false;
            continue;
        }
        if ($mtime >= $now - 7 * 24 * 3600) {
            continue;
        }
        $basename = basename($file);
        if (!is_valid_rotated_log($basename)) {
            error_log('Security: Suspicious log filename detected: ' . $basename);
            continue;
        }
        $gz = gzopen($file . '.gz', 'wb9');
        if ($gz === false) {
            error_log('LOG ROTATE: Unable to gzip ' . $file);
            $ok = false;
            continue;
        }
        $fp = fopen($file, 'rb');
        if ($fp === false) {
            gzclose($gz);
            if (!unlink($file . '.gz')) {
                error_log('LOG ROTATE: Unable to clean up gzip for ' . $file);
            }
            $ok = false;
            continue;
        }
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk !== false) {
                gzwrite($gz, $chunk);
            }
        }
        fclose($fp);
        gzclose($gz);
        if (!unlink($file)) {
            error_log('LOG ROTATE: Unable to delete original log ' . $file);
            $ok = false;
        }
    }

    return $ok;
}

function purge_old_archives(string $log_dir, int $now): bool {
    $files = glob($log_dir . '/error_log.*.gz');
    if ($files === false) {
        error_log('LOG ROTATE: Unable to list archived logs for cleanup');
        return false;
    }

    $ok = true;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $basename = basename($file);
        if (!is_valid_rotated_log(str_replace('.gz', '', $basename))) {
            error_log('Security: Suspicious archived log filename detected: ' . $basename);
            continue;
        }
        $mtime = filemtime($file);
        if ($mtime === false) {
            error_log('LOG ROTATE: Unable to read mtime for archive ' . $file);
            $ok = false;
            continue;
        }
        if ($mtime < $now - 30 * 24 * 3600 && !unlink($file)) {
            error_log('LOG ROTATE: Unable to delete archived log ' . $file);
            $ok = false;
        }
    }

    return $ok;
}

/* ===================== AUTHENTICATION GUARDS ===================== */

/**
 * Require admin authentication
 * Ensures only authenticated administrators can access admin endpoints
 * Uses $_SESSION['admin_email'] for authentication
 *
 * @param array $config Application configuration
 * @return void Exits with 403 JSON response if authentication fails
 */
function require_admin_auth(array $config): void {
    // Ensure session is started (includes automatic timeout enforcement)
    init_secure_session();

    // Check if admin email is set in session
    $admin_email = $_SESSION['admin_email'] ?? null;

    if (!is_string($admin_email) || $admin_email === '') {
        // Not authenticated as admin
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Admin authentication required']);
        exit;
    }

    // Verify the email is still in the admin list
    $admin_emails_path = dirname(__DIR__) . '/config/admin_emails.txt';

    if (!is_readable($admin_emails_path)) {
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Admin configuration error']);
        exit;
    }

    $admin_emails_lines = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($admin_emails_lines === false) {
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Failed to load admin list']);
        exit;
    }

    $is_admin_verified = false;
    foreach ($admin_emails_lines as $line) {
        if ($line !== '' && strcasecmp(trim($line), $admin_email) === 0) {
            $is_admin_verified = true;
            break;
        }
    }

    if (!$is_admin_verified) {
        // Email is no longer in admin list - revoke access
        unset($_SESSION['admin_email']);
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Admin privileges revoked']);
        exit;
    }

    // Admin is authenticated and authorized
}

/**
 * Require voter authentication
 * Ensures only authenticated voters can access voting endpoints
 * Uses $_SESSION['voter_email'] for authentication
 * Allows admins to view in read-only mode
 *
 * @param array $config Application configuration
 * @return void Redirects to login if authentication fails
 */
function require_voter_auth(array $config): void {
    // Ensure session is started (includes automatic timeout enforcement)
    init_secure_session();

    // Check if voter email is set in session
    $voter_email = $_SESSION['voter_email'] ?? null;

    // Check if user is logged in as admin (admins can view vote page in read-only mode)
    $admin_email = $_SESSION['admin_email'] ?? null;

    if (!is_string($voter_email) || $voter_email === '') {
        // Not authenticated as voter - check if admin
        if (!is_string($admin_email) || $admin_email === '') {
            // Not authenticated as voter or admin - redirect to login
            $_SESSION['flash'] = ['err', 'You must log in to access the vote.'];
            header('Location: ' . ($config['base_url'] ?? '/'));
            exit;
        }
        // Admin is logged in - allow them to view the page in read-only mode
        return;
    }

    // Load helpers for allowed emails check
    if (!function_exists('load_voter_emails')) {
        require_once __DIR__ . '/helpers.php';
    }

    // Verify the email is still in the allowed voters list
    $voter_emails = load_voter_emails($config['voter_emails']);

    if (!isset($voter_emails[$voter_email])) {
        // Email is no longer in allowed list - revoke access
        unset($_SESSION['voter_email']);
        $_SESSION['flash'] = ['err', 'Your access to the vote has been revoked.'];
        header('Location: ' . ($config['base_url'] ?? '/'));
        exit;
    }

    // Voter is authenticated and authorized
}

/* ===================== CLOUDFLARE TURNSTILE ===================== */

/**
 * Verify Cloudflare Turnstile token
 *
 * @param string $token Turnstile response token
 * @param string $secret Turnstile secret key
 * @param string $ip Client IP address
 * @return bool True if verification succeeds
 */
function verify_turnstile(string $token, string $secret, string $ip = ''): bool {
    if (!$token || !$secret) return false;
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $ip,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code !== 200 || !$response) return false;
    $data = json_decode($response, true);
    return ($data['success'] ?? false) === true;
}
