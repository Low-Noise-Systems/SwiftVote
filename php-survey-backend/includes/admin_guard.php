<?php
/**
 * Centralized Admin Guard
 *
 * Include this file at the beginning of ALL admin pages.
 * It intelligently determines if authentication is required based on the action.
 *
 * Usage:
 *   require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard.php';
 *
 * Public actions (no auth required):
 *   - request_link: Request admin login link
 *   - verify: Verify admin token
 *   - disconnect: Logout
 *
 * All other actions require admin authentication.
 */

declare(strict_types=1);

// Ensure security.php is loaded
if (!function_exists('require_admin_auth')) {
    throw new Exception('admin_guard.php requires security.php to be loaded first');
}
/** @var array $config */
if (!isset($config) || !is_array($config)) {
    $config = require_once __DIR__ . '/config_loader.php';
}

// Determine current action
$action = $_GET['action'] ?? $_POST['action'] ?? 'default';

// Define actions that don't require admin authentication
// These are typically login/logout related actions
$public_actions = [
    'request_link',  // Admin login request
    'verify',        // Admin token verification
    'disconnect',    // Logout (anyone can logout)
    'admin',         // Default admin page (shows login form)
    'default',       // Default action (shows login form)
];

// Check if current action requires authentication
$requires_auth = !in_array($action, $public_actions, true);

if ($requires_auth) {
    // CRITICAL: Block access to authenticated admin-only content
    // This ensures consistent protection across all admin pages
    require_admin_auth($config);
}

// Note: For pages that are ALWAYS admin-only (no public actions),
// you can use admin_guard_strict.php instead for better clarity
?>
