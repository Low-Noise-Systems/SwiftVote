<?php
declare(strict_types=1);

// Load security and configuration
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
$config = require_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';
include_once __DIR__ . '/../../php-survey-backend/includes/helpers.php';
include_once __DIR__ . '/../../php-survey-backend/includes/views.php';

// Configure error logging and rotate logs
$log_dir = __DIR__ . '/../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

// Initialize secure session (includes automatic timeout enforcement)
init_secure_session();

// Route basic actions (disconnect)
$action = $_POST['action'] ?? $_GET['action'] ?? null;
if ($action === 'disconnect') {
    include_once __DIR__ . '/../../php-survey-backend/actions/disconnect.php';
}

// SECURITY: Require voter authentication
require_voter_auth($config);

// Check if voting is open (admins can bypass this check)
$is_admin = isset($_SESSION['admin_email']);
$voting_open = is_voting_open($config['voting_state_json']);

if (!$voting_open && !$is_admin) {
    // Redirect non-admin users to main page if voting is closed
    header('Location: ../');
    exit;
}

// Get verified voter email from session
$verified = $_SESSION['voter_email'] ?? null;

// Load announcement data
$announcement = load_json_file($config['announcement_json']);

// Check if voter has already voted
$has_voted = false;
if (!$config['allow_multiple_votes'] && !empty($verified)) {
    $has_voted = has_voted($verified, $config['votes_dir'], $config['secret_key']);
}

// If announcement is disabled OR voter has already voted, redirect directly to vote page
if (empty($announcement['enabled']) || $has_voted) {
    header('Location: ../vote/');
    exit;
}

// Handle continue action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'continue') {
    require_csrf($_POST['csrf'] ?? '');
    // Mark announcement as acknowledged
    $_SESSION['announcement_acknowledged'] = true;
    // Redirect to vote page
    header('Location: ../vote/');
    exit;
}

/* ===================== RENDER ===================== */
header_html('Annonce - ' . $config['app_name'], $config['app_name'], $config, '../assets/css/style.css');

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
flash_html($flash);

// Display announcement
?>
<section class="flow-step">
    <p class="step-eyebrow">Step 3 · Why this vote matters</p>
    <?php if (!empty($announcement['title'])): ?>
        <h2 class="step-title"><?= h($announcement['title']) ?></h2>
    <?php else: ?>
        <h2 class="step-title">A quick announcement before you vote</h2>
    <?php endif; ?>

    <div class="announcement-content">
        <?= $announcement['content'] ?>
    </div>

    <!-- Logo: keep small and centered below the announcement text -->
    <img
        src="../assets/images/org-logo-placeholder.svg"
        alt="Organization logo placeholder"
        class="announcement-logo"
        loading="lazy"
    />

    <form method="POST" action="" class="flow-form announcement-form">
        <input type="hidden" name="action" value="continue">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button type="submit" class="primary-btn announcement-submit-btn">
            I'm ready to view the candidates
        </button>
    </form>
</section>

<?php
include __DIR__ . '/../footer.php';
?>
