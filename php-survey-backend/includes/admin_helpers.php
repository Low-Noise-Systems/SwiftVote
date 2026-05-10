<?php
/**
 * Admin Helpers
 *
 * Centralized helper functions for admin pages.
 * Include this file to get access to all shared functionality.
 *
 * Provides:
 * - Core helpers from helpers.php (h, load_json_file, save_json_file, csrf_token, etc.)
 * - Security functions from security.php (init_secure_session, csp_nonce, etc.)
 * - Admin-specific view functions (admin_header_html, admin_flash_html)
 * - Vote parameters management (load_vote_params, save_vote_params)
 * - Admin email management (load_admin_emails, save_admin_emails)
 * - App settings management (default_app_settings, load_app_settings)
 * - Date formatting utilities
 */
declare(strict_types=1);

// Load core dependencies
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/helpers.php';

/* ===================== ADMIN VIEW FUNCTIONS ===================== */

/**
 * Render admin page header HTML
 * Different from public header_html - no flow-shell/flow-stack classes
 *
 * @param string $title Page title
 * @param string $app Application name
 * @param array $config Application config (for turnstile)
 * @param string $css_path Path to main CSS file
 * @param string $admin_css_path Path to admin CSS file
 */
function admin_header_html(
    string $title,
    string $app,
    array $config = [],
    string $css_path = '../assets/css/style.css?v=2',
    string $admin_css_path = '../assets/css/admin.css?v=2'
): void {
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="' . h($css_path) . '">';
    echo '<link rel="stylesheet" href="' . h($admin_css_path) . '">';
    if (!empty($config['turnstile_site_key'])) {
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>';
    }
    echo '</head><body><div class="main-wrapper"><div class="card">';
    echo '<h1>' . h($app) . '</h1>';
}

/**
 * Render flash message for admin pages
 *
 * @param array|null $flash Flash message array [type, message]
 */
function admin_flash_html(?array $flash): void {
    if (!$flash) return;
    [$type, $msg] = $flash;
    $cls = $type === 'ok' ? 'ok' : 'err';
    echo '<div class="flash ' . $cls . '">' . h($msg) . '</div>';
}

/* ===================== VOTE PARAMETERS ===================== */

/**
 * Load vote parameters from JSON file
 *
 * @param string $params_file Path to vote_params.json
 * @return array Vote parameters with defaults applied
 */
function load_vote_params(string $params_file): array {
    $defaults = [
        'min_picks' => 3,
        'max_picks' => 9,
        'allow_multiple_votes' => false,
        'include_candidate_names_in_receipt' => false,
        'show_manifestos' => true,
        'anonymous_voter_export' => false,
        'voting_method' => 'approval',
        'stv_require_full_ranking' => false,
        'stv_min_rankings' => 1
    ];

    if (!file_exists($params_file)) {
        // Auto-create the file with default values
        $dir = dirname($params_file);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('VOTE PARAMS: Unable to create directory ' . $dir);
            return $defaults;
        }
        save_vote_params($params_file, $defaults);
        return $defaults;
    }

    $data = load_json_file($params_file);
    return array_merge($defaults, $data);
}

/**
 * Save vote parameters to JSON file
 *
 * @param string $params_file Path to vote_params.json
 * @param array $params Vote parameters to save
 * @return bool True on success
 */
function save_vote_params(string $params_file, array $params): bool {
    return save_json_file($params_file, $params);
}

/* ===================== ADMIN EMAIL MANAGEMENT ===================== */

/**
 * Load admin emails from text file
 *
 * @param string $path Path to admin_emails.txt
 * @return array Array of admin email addresses (lowercase, sorted)
 */
function load_admin_emails(string $path): array {
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $emails = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (filter_var($line, FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower($line);
        }
    }
    $emails = array_values(array_unique($emails));
    sort($emails);
    return $emails;
}

/**
 * Save admin emails to text file
 *
 * @param string $path Path to admin_emails.txt
 * @param array $emails Array of email addresses
 * @return bool True on success
 */
function save_admin_emails(string $path, array $emails): bool {
    $emails = array_values(array_unique($emails));
    sort($emails);
    $content = "# List of admin emails, one per line\n";
    foreach ($emails as $email) {
        $content .= $email . "\n";
    }

    $tmp = $path . '.tmp';
    $fp = fopen($tmp, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        if (file_exists($tmp)) unlink($tmp);
        return false;
    }
    ftruncate($fp, 0);
    fwrite($fp, $content);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!rename($tmp, $path)) {
        if (file_exists($tmp)) unlink($tmp);
        return false;
    }
    return true;
}

/* ===================== APP SETTINGS ===================== */

/**
 * Get default app settings
 *
 * @param array $config Application configuration
 * @return array Default settings
 */
function default_app_settings(array $config): array {
    return [
        'app_name' => (string)($config['app_name'] ?? 'Community Election'),
        'mail_mode' => ($config['mail_mode'] ?? 'hosting') === 'smtp' ? 'smtp' : 'hosting',
        'hosting_from_address' => (string)($config['smtp']['from']['address'] ?? ''),
        'hosting_from_name' => (string)($config['smtp']['from']['name'] ?? ''),
        'hosting_reply_to_address' => (string)($config['smtp']['reply_to']['address'] ?? ''),
        'hosting_reply_to_name' => (string)($config['smtp']['reply_to']['name'] ?? ''),
    ];
}

/**
 * Load app settings from JSON file with defaults
 *
 * @param string $path Path to app_settings.json
 * @param array $defaults Default values
 * @return array Merged settings
 */
function load_app_settings(string $path, array $defaults): array {
    if (!file_exists($path)) return $defaults;
    $data = load_json_file($path);
    return array_merge($defaults, $data);
}

/* ===================== DATE FORMATTING ===================== */

/**
 * Format a DateTimeImmutable for admin display
 *
 * @param DateTimeImmutable $datetime Date to format
 * @return string Formatted date string
 */
function format_program_mode_datetime(DateTimeImmutable $datetime): string {
    static $months = [
        '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
        '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
        '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'
    ];
    $day = $datetime->format('d');
    $month_label = $months[$datetime->format('m')] ?? $datetime->format('m');
    $year = $datetime->format('Y');
    $time = $datetime->format('H:i');
    return "{$day} {$month_label} {$year} at {$time}";
}

/**
 * Format an ISO datetime string for admin display
 *
 * @param string|null $iso_datetime ISO datetime string
 * @return string Formatted date string or original on error
 */
function format_program_mode_iso(?string $iso_datetime): string {
    if (empty($iso_datetime)) return '';
    try {
        $dt = new DateTimeImmutable($iso_datetime, new DateTimeZone('UTC'));
        return format_program_mode_datetime($dt);
    } catch (Exception $e) {
        return (string) $iso_datetime;
    }
}

/* ===================== VOTING MODE MANAGEMENT ===================== */

/**
 * Set the voting control mode (manual or scheduled)
 *
 * @param string $state_file Path to voting_state.json
 * @param string $mode 'scheduled' or 'manual'
 * @return bool True on success
 */
function set_voting_mode(string $state_file, string $mode): bool {
    $state = load_voting_state($state_file);
    if (!in_array($mode, ['scheduled', 'manual'], true)) return false;
    $was_open = state_is_open($state);

    $state['mode'] = $mode;

    if ($mode === 'manual') {
        $state['manual_override'] = $was_open;
    } else {
        if ($was_open) {
            $state['period'] = [
                'start' => now_iso(),
                'end' => null
            ];
        } else {
            $state['period'] = ['start' => null, 'end' => null];
        }
    }

    return save_json_file($state_file, $state);
}

/* ===================== CANDIDATE DATA MANAGEMENT ===================== */

/**
 * Load full candidates data structure
 *
 * @param string $path Path to candidates.json
 * @return array Full data with 'candidates' key
 */
function load_candidates_data(string $path): array {
    if (!file_exists($path)) return ['candidates' => []];
    $data = load_json_file($path);
    if (!isset($data['candidates']) || !is_array($data['candidates'])) {
        $data['candidates'] = [];
    }
    return $data;
}

/**
 * Save candidates data structure
 *
 * @param string $path Path to candidates.json
 * @param array $data Data to save
 * @return bool True on success
 */
function save_candidates_data(string $path, array $data): bool {
    return save_json_file($path, $data);
}
