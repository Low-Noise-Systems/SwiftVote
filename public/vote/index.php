<?php
declare(strict_types=1);

// Load security and configuration
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
$config = require_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';
include_once __DIR__ . '/../../php-survey-backend/includes/helpers.php';
include_once __DIR__ . '/../../php-survey-backend/includes/mail.php';
include_once __DIR__ . '/../../php-survey-backend/includes/views.php';

// Configure error logging and rotate logs
$log_dir = __DIR__ . '/../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

// SECURITY: Require voter authentication (enforces separate voter/admin sessions)
require_voter_auth($config);

// Check if voting is open (admins can bypass this check to review the form)
$is_admin_session = !empty($_SESSION['admin_email']);
$voting_open = is_voting_open($config['voting_state_json']);

if (!$voting_open && !$is_admin_session) {
    // Redirect non-admin users to main page if voting is closed
    $_SESSION['flash'] = ['error', 'Voting is currently closed. Please check back later.'];
    header('Location: ../');
    exit;
}

// Check if announcement is enabled and voter hasn't voted yet
// If so, redirect to announcement page (unless they've already acknowledged it)
if (!$is_admin_session) {
    $announcement = load_json_file($config['announcement_json']);
    $verified = $_SESSION['voter_email'] ?? null;
    $has_voted_check = false;
    if (!$config['allow_multiple_votes'] && !empty($verified)) {
        $has_voted_check = has_voted($verified, $config['votes_dir'], $config['secret_key']);
    }
    // Redirect to announcement if enabled and voter hasn't voted yet and hasn't acknowledged
    $announcement_acknowledged = $_SESSION['announcement_acknowledged'] ?? false;
    if (!empty($announcement['enabled']) && !$has_voted_check && !$announcement_acknowledged) {
        header('Location: ../announcement/');
        exit;
    }
}

/* ===================== ROUTER ===================== */
$action = $_GET['action'] ?? $_POST['action'] ?? 'vote';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Get verified voter email from session (set by voter_guard.php)
$verified = $_SESSION['voter_email'] ?? null;

/* ===================== ACTIONS ===================== */
if ($action === 'cast')
    include_once __DIR__ . '/../../php-survey-backend/actions/cast.php';
if ($action === 'disconnect')
    include_once __DIR__ . '/../../php-survey-backend/actions/disconnect.php';
if ($action === 'ping') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
    exit;
}

/* ===================== RENDER ===================== */
header_html('Vote - ' . $config['app_name'], $config['app_name'], $config, '../assets/css/style.css');

// Check if logged in as admin
$is_admin_session = !empty($_SESSION['admin_email']);

// Show back link for admins
if ($is_admin_session) {
    echo '<div class="back-link"><a href="../admin/">Back to Admin Panel</a></div>';
}

flash_html($flash);

// Set flag to indicate if flash was shown
$flash_was_shown = !empty($flash);

// Check if user has already voted
$has_voted = false;
if (!$config['allow_multiple_votes'] && !empty($verified)) {
    $has_voted = has_voted($verified, $config['votes_dir'], $config['secret_key']);
}

if ($is_admin_session) {
    // Show admin-only message
    echo '<div class="admin-alert warning">';
    echo '<strong>⚠️ You are logged in as an administrator</strong><br>';
    echo 'To vote, you must <strong>disconnect</strong> from your administrator session and use the <strong>normal voting page</strong>.<br>';
    echo 'Administrators cannot vote from their admin session for security and role separation reasons.<br>';
    echo '<small>You can review the voting configuration below (form disabled).</small>';
    echo '</div>';

    // Show greyed-out form for review
    $is_read_only = true;
    $show_voted_message = false;
    include __DIR__ . '/../../php-survey-backend/views/vote_form.php';
} elseif ($has_voted) {
    // Show greyed-out form with "already voted" message
    $is_read_only = true;
    $show_voted_message = true;
    include __DIR__ . '/../../php-survey-backend/views/vote_form.php';
} else {
    // Show active vote form
    $is_read_only = false;
    $show_voted_message = false;
    include __DIR__ . '/../../php-survey-backend/views/vote_form.php';
}

// Set admin flag for footer (for disconnect button)
$is_admin = $is_admin_session;

// No footer links - using back link at top for navigation
include __DIR__ . '/../footer.php';
?>