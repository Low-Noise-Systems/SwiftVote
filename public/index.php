<?php
declare(strict_types=1);

// Load security and configuration
include_once __DIR__ . '/../php-survey-backend/includes/security.php';
$config = require_once __DIR__ . '/../php-survey-backend/includes/config_loader.php';
include_once __DIR__ . '/../php-survey-backend/includes/helpers.php';
include_once __DIR__ . '/../php-survey-backend/includes/mail.php';
include_once __DIR__ . '/../php-survey-backend/includes/views.php';
include_once __DIR__ . '/../php-survey-backend/includes/debug.php';

// Initialize secure session (includes automatic timeout enforcement)
init_secure_session();

// Configure error logging
$log_dir = __DIR__ . '/../php-survey-backend/logs';
setup_error_logging($log_dir);

// Rotate logs
rotate_logs($log_dir);

/* ===================== ROUTER ===================== */
$action = $_GET['action'] ?? $_POST['action'] ?? 'index';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ===================== ACTIONS ===================== */
if ($action === 'debug_login') { handle_debug_login_action($config); exit; }
if ($action === 'request_link') include_once __DIR__ . '/../php-survey-backend/actions/request_link.php';
if ($action === 'verify') include_once __DIR__ . '/../php-survey-backend/actions/verify.php';
if ($action === 'cast') include_once __DIR__ . '/../php-survey-backend/actions/cast.php';
if ($action === 'disconnect') include_once __DIR__ . '/../php-survey-backend/actions/disconnect.php';

/* ===================== RENDER ===================== */
header_html($config['app_name'], $config['app_name'], $config);
flash_html($flash);

// Check voter authentication (separate from admin)
$verified = $_SESSION['voter_email'] ?? null;

// Check if user has admin privileges (for showing admin links)
$is_admin = isset($_SESSION['admin_email']);

// Check if voting is open
$voting_open = is_voting_open($config['voting_state_json']);

// Redirect authenticated voters to the vote page (only if voting is open or they are admin)
if ($verified && ($voting_open || $is_admin)) {
    header('Location: vote/'); exit;
}

// If admin but not voter, show admin landing page
if ($is_admin) {
    echo '<div class="admin-landing-alert">';
    echo '<h3>👋 You are logged in as an administrator</h3>';
    echo '<p>You have access to administration features. Choose an option below:</p>';
    echo '<div class="admin-landing-alert-actions">';
    echo '<a href="admin/" class="admin-landing-btn primary">📊 Admin Panel</a>';
    echo '<a href="?action=disconnect" class="admin-landing-btn danger">🚪 Disconnect</a>';
    echo '</div>';
    echo '<hr>';
    echo '<p class="muted">This page is for voters. If you wish to vote, please disconnect from your admin session and request a voting link.</p>';
    echo '</div>';
}

// If voting is closed and user is not an admin, show closed message
if (!$voting_open && !$is_admin) {
    echo '<div class="voting-closed-alert">';
    echo '<h3>🔒 Voting is Currently Closed</h3>';
    echo '</div>';
    include __DIR__ . '/footer.php';
    exit;
}

// Display debug panel if debug mode is enabled
render_debug_panel($config);

// Display election brief before login
$home_message_path = __DIR__ . '/../php-survey-backend/content/home_message.php';
if (is_readable($home_message_path)) {
    include $home_message_path;
}

// Display voter login form
include __DIR__ . '/../php-survey-backend/views/request_form.php';

if ($is_admin) {
	$footer_links = [
		['href' => 'admin/', 'label' => 'Open Admin Panel'],
	];
}
include __DIR__ . '/footer.php';
?>
