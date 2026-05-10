<?php
/**
 * Strict Admin Guard (No Public Actions)
 *
 * Include this file at the beginning of admin pages that have NO public actions.
 * It immediately blocks all non-admin users.
 *
 * Usage:
 *   require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard_strict.php';
 *
 * Use this for pages like:
 *   - /candidates/ (candidate management)
 *   - /vote-settings/ (vote configuration)
 *   - /vote-key/ (key management)
 *   - Any admin page without login/logout functionality
 *
 * For pages with login functionality (like /admin/index.php), use admin_guard.php instead.
 */

declare(strict_types=1);

// Ensure security.php is loaded
if (!function_exists('require_admin_auth')) {
    throw new Exception('admin_guard_strict.php requires security.php to be loaded first');
}
/** @var array $config */
if (!isset($config) || !is_array($config)) {
    $config = require_once __DIR__ . '/config_loader.php';
}

// CRITICAL: Immediately block all non-admin users
// No public actions allowed on this page
require_admin_auth($config);
?>
