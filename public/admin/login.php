<?php
declare(strict_types=1);

// Load security functions
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
$config = require_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';
$base_url = resolve_base_url($config);
include_once __DIR__ . '/../../php-survey-backend/includes/helpers.php';
include_once __DIR__ . '/../../php-survey-backend/includes/mail.php';
include_once __DIR__ . '/../../php-survey-backend/includes/views.php';
include_once __DIR__ . '/../../php-survey-backend/includes/debug.php';

// Initialize secure session (includes automatic timeout enforcement)
init_secure_session();

// Configure error logging
$log_dir = __DIR__ . '/../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

// Rate limiter
require_once __DIR__ . '/../../php-survey-backend/includes/rate_limiter.php';

/* ===================== ROUTER ===================== */
$action = $_GET['action'] ?? $_POST['action'] ?? 'login';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ===================== ADMIN ALREADY LOGGED IN? ===================== */
// If already authenticated as admin, redirect to admin panel
if (isset($_SESSION['admin_email'])) {
	header('Location: index.php'); exit;
}

if ($action === 'disconnect') {
	require_csrf($_POST['csrf'] ?? '');
	unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
	$_SESSION['flash'] = ['ok', 'Disconnected.'];
	header('Location: ' . $base_url); exit;
}

/* ===================== SETUP TOKEN LOGIN ===================== */
// Temporary admin access via setup token (for initial configuration before email works)
$setup_token = $_GET['setup_token'] ?? '';
if ($setup_token !== '') {
	$app_settings_file = $config['app_settings_json'] ?? (__DIR__ . '/../../php-survey-backend/config/app_settings.json');
	$app_settings = [];
	if (file_exists($app_settings_file)) {
		$content = file_get_contents($app_settings_file);
		if ($content !== false) {
			$app_settings = json_decode($content, true) ?: [];
		}
	}

	$stored_token = $app_settings['setup_admin_token'] ?? '';
	if ($stored_token !== '' && hash_equals($stored_token, $setup_token)) {
		// Valid setup token - log in as first admin email
		$admin_emails_path = __DIR__ . '/../../php-survey-backend/config/admin_emails.txt';
		$admin_emails = [];
		if (file_exists($admin_emails_path)) {
			$lines = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
			foreach ($lines as $line) {
				$line = trim($line);
				if ($line !== '' && !str_starts_with($line, '#')) {
					$admin_emails[] = strtolower($line);
				}
			}
		}

		if (!empty($admin_emails)) {
			$_SESSION['admin_email'] = $admin_emails[0];
			$_SESSION['admin_via_setup_token'] = true;
			$_SESSION['last_activity'] = time();
			$_SESSION['flash'] = ['ok', 'Logged in via setup token. Please configure email settings, then disable temporary access.'];
			header('Location: index.php'); exit;
		}
	}

	// Invalid or expired token
	$_SESSION['flash'] = ['err', 'Invalid or expired setup token.'];
	header('Location: login.php'); exit;
}

/* ===================== ADMIN ALLOWED ===================== */
$admin_emails_path = __DIR__ . '/../../php-survey-backend/config/admin_emails.txt';
$admin_allowed = function(string $email) use ($admin_emails_path): bool {
	if (!file_exists($admin_emails_path)) return false;
	$lines = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
	foreach ($lines as $line) {
		if (strtolower(trim($line)) === strtolower($email)) return true;
	}
	return false;
};

/* ===================== ACTIONS ===================== */

if ($action === 'debug_login') { handle_debug_login_action($config); exit; }

if ($action === 'request_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$email = strtolower(trim($_POST['email'] ?? ''));

	// Verify Turnstile (only if enabled)
	if (!empty($config['turnstile_enabled'])) {
		$turnstile_token = $_POST['cf-turnstile-response'] ?? '';
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		if (!verify_turnstile($turnstile_token, $config['turnstile_secret_key'], $ip)) {
			$_SESSION['flash'] = ['err', 'Security verification failed. Please try again.'];
			header('Location: login.php'); exit;
		}
	}

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$_SESSION['flash'] = ['err', 'Invalid email address'];
		header('Location: login.php'); exit;
	}

	// New file-based rate limiting with IP reputation scoring
	$limiter = new FileBasedRateLimiter();
	$is_admin_email = $admin_allowed($email);

	// Check rate limit with IP reputation system (admin context)
	$rate_result = $limiter->check_access($ip, $email, $is_admin_email, 'admin');

	// Handle blocking (IP reputation too low)
	if ($rate_result['blocked']) {
		http_response_code(429);
		$_SESSION['flash'] = ['err', "Too many attempts. Try again in {$rate_result['minutes']} minutes."];
		header('Location: login.php'); exit;
	}

	// Handle cooldown or email not authorized (show neutral message)
	if (!$rate_result['send_email']) {
		$_SESSION['flash'] = ['ok', 'If your address is authorized for administration, you will receive a verification email.'];
		header('Location: login.php'); exit;
	}

	// At this point: email is valid admin email, not in cooldown, and rate limit is OK
	if (!$is_admin_email) {
		// Should not reach here, but safety check
		$_SESSION['flash'] = ['ok', 'If your address is authorized for administration, you will receive a verification email.'];
		header('Location: login.php'); exit;
	}

	// Create token and store (with pruning)
	$token = generate_token($email, $config['secret_key']);
	$tokens = load_json_file($config['tokens_json']);
	$now = time();
	$tokens = prune_tokens($tokens, $now);
	$tokens[$token] = ['email'=>$email, 'expires'=>$now + (int)$config['token_ttl_secs'], 'used'=>false];
	save_json_file($config['tokens_json'], $tokens);

	// Build an admin-specific verify link
	$admin_base = $base_url;
	if (str_ends_with($admin_base, 'index.php')) {
		$admin_base = substr($admin_base, 0, -strlen('index.php')) . 'admin/login.php';
	} else {
		$admin_base = rtrim($admin_base, '/') . '/admin/login.php';
	}
	$link = $admin_base . '?action=verify&token=' . urlencode($token);
	$sent = send_smtp_mail(
		$config,
		$email,
		mail_subject('verify_admin', $config),
		render_verify_admin_mail($link, $config)
	);
	if (!$sent) { error_log('MAIL: verify mail failed'); }

	// Always show the same neutral message to avoid information leaks
	$_SESSION['flash'] = ['ok', 'If your address is authorized for administration, you will receive a verification email.'];
	header('Location: login.php'); exit;
}

// Include the admin verification action (handles both GET and POST)
include __DIR__ . '/../../php-survey-backend/actions/admin_verify.php';

/* ===================== RENDER ===================== */
// Use public CSS for login page (not admin CSS)
header_html('Admin Login', $config['app_name'], $config, '../assets/css/style.css');
flash_html($flash);

// Display debug panel if debug mode is enabled
render_debug_panel($config);
?>

<h2>Administrator Login</h2>
<p class="muted">Enter your admin email address to receive a secure login link.</p>

<form method="post" class="row" data-prevent-multi-submit>
	<input type="hidden" name="action" value="request_link">
	<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
	<label class="row">
		<span class="muted">Your admin email</span>
		<input type="email" name="email" required placeholder="admin@company.com" autocomplete="email">
	</label>
	<?php include_once __DIR__ . '/../../php-survey-backend/includes/turnstile.php'; render_turnstile_widget($config, 'submit-btn'); ?>
	<button type="submit" id="submit-btn" <?php if (!empty($config['turnstile_enabled'])): ?>disabled<?php endif; ?>>Send verification link</button>
</form>

<p class="muted">Access reserved for authorized administrators only.</p>

<?php
include __DIR__ . '/../footer.php';
?>
