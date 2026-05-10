<?php
declare(strict_types=1);

// Load admin helpers (includes security.php, helpers.php, and admin-specific functions)
require_once __DIR__ . '/../../php-survey-backend/includes/admin_helpers.php';

// Initialize secure session (includes automatic timeout enforcement)
init_secure_session();

// Configure error logging and rotate logs
$log_dir = __DIR__ . '/../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

/* ===================== LOAD CONFIG ===================== */
$config_path = __DIR__ . '/../../php-survey-backend/config/config.php';
if (!file_exists($config_path)) { http_response_code(500); exit('Critical Error: Configuration file not found.'); }
$config = require $config_path;

/* ======== SECURITY: TRUSTED HOSTS + FIXED BASE URL ======== */
$trusted = $config['trusted_hosts'] ?? [];
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($trusted && !in_array($host, $trusted, true)) { http_response_code(400); exit('Unauthorized host'); }
$base_url = resolve_base_url($config);

/* ======== SECRET GUARD ======== */
require_configured_secret($config);

/* ======== ENSURE DATA DIR ======== */
if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0750, true) && !is_dir($config['data_dir'])) {
	http_response_code(500); exit('Critical Error: Unable to create data directory.');
}

// Shared CSP for admin console (allows Turnstile + Quill/CDN assets)
send_default_csp($config);

/* ===================== EXTENDED VOTING STATE HELPERS ===================== */
// These functions extend helpers.php with admin-specific voting period handling

function set_voting_period_with_optional_end(string $state_file, string $start, string $end = ''): bool {
	$state = load_voting_state($state_file);
	try {
		$start_dt = new DateTimeImmutable($start, new DateTimeZone('UTC'));
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$state['period'] = [
			'start' => $start_dt->format('Y-m-d\TH:i:s\Z'),
			'end' => null
		];

		// End date is optional
		if (!empty($end)) {
			$end_dt = new DateTimeImmutable($end, new DateTimeZone('UTC'));
			if ($end_dt <= $start_dt) return false;
			$state['period']['end'] = $end_dt->format('Y-m-d\TH:i:s\Z');
		}

		// Track if vote has ever been opened (if period is active now or in the future)
		if ($start_dt <= $now) {
			$state['vote_ever_opened'] = true;
		}

		return save_json_file($state_file, $state);
	} catch (Exception $e) {
		return false;
	}
}

function set_manual_override_with_tracking(string $state_file, bool $open): bool {
	$state = load_voting_state($state_file);
	$state['manual_override'] = $open;
	// Track if vote has ever been opened (for onboarding)
	if ($open) {
		$state['vote_ever_opened'] = true;
	}
	return save_json_file($state_file, $state);
}

function get_voting_status_extended(string $state_file, string $votes_dir): array {
	$state = load_voting_state($state_file);
	$vote_count = count_votes($votes_dir);
	$is_open = is_voting_open($state_file);
	$locked = are_candidates_locked($votes_dir);

	$period = $state['period'];
	$period_configured = !empty($period['start']);
	$is_active = false;
	$is_future = false;

	if ($period_configured) {
		try {
			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$start = new DateTimeImmutable($period['start'], new DateTimeZone('UTC'));

			if (!empty($period['end'])) {
				$end = new DateTimeImmutable($period['end'], new DateTimeZone('UTC'));
				$is_active = ($now >= $start && $now <= $end);
			} else {
				// No end date: active if we've passed start
				$is_active = $now >= $start;
			}
			$is_future = ($now < $start);
		} catch (Exception $e) {
			$period_configured = false;
		}
	}

	return [
		'is_open' => $is_open,
		'candidates_locked' => $locked,
		'vote_count' => $vote_count,
		'period' => $period,
		'period_configured' => $period_configured,
		'period_active' => $is_active,
		'period_future' => $is_future,
		'manual_override' => $state['manual_override'],
		'mode' => $state['mode']
	];
}

/* ===================== MAIL ===================== */
// Include mail functions from shared mail.php
// This includes: load_template, mail_subject, render_verify_mail, render_receipt_mail
require_once __DIR__ . '/../../php-survey-backend/includes/mail.php';

/* ===================== ONBOARDING ===================== */
// Include onboarding helpers for admin guidance
require_once __DIR__ . '/../../php-survey-backend/includes/onboarding.php';

/* ===================== DEBUG ===================== */
// Include debug helpers (for is_debug_enabled() function)
require_once __DIR__ . '/../../php-survey-backend/includes/debug.php';

/* ===================== RATE LIMIT ===================== */
// Using new file-based rate limiter with IP reputation scoring
require_once __DIR__ . '/../../php-survey-backend/includes/rate_limiter.php';

/* ===================== ROUTER ===================== */
$action = $_GET['action'] ?? $_POST['action'] ?? 'admin';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ===================== SECURITY: ADMIN GUARD (STRICT) ===================== */
// Use centralized strict admin guard - login is now on separate page (login.php)
// This page is 100% protected, no public actions
// Non-authenticated users are redirected to login.php
if (!isset($_SESSION['admin_email'])) {
	$_SESSION['flash'] = ['err', 'Please log in to access this page.'];
	header('Location: login.php'); exit;
}
require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard_strict.php';

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

$is_admin = false;
if (isset($_SESSION['admin_email']) && $admin_allowed($_SESSION['admin_email'])) {
	$is_admin = true;
}

/* ===================== ACTIONS ===================== */
// Note: request_link and verify actions moved to login.php for better separation

// Handle disconnect action
if ($action === 'disconnect') {
	// Clear both admin and voter sessions for security
	unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
	$_SESSION['flash'] = ['ok', 'Disconnected.'];
	header('Location: ' . $base_url); exit;
}

// Note: download_csv action removed - decryption now happens client-side via download.js

/* ===================== ADMIN-ONLY ACTIONS BELOW ===================== */
// All actions below this point are protected by admin_guard.php (line 360)
// Only authenticated admins can execute these actions

/* ===================== VOTING MANAGEMENT ACTIONS ===================== */
if ($is_admin && $action === 'set_mode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$mode = $_POST['mode'] ?? '';
	if (set_voting_mode($config['voting_state_json'], $mode)) {
		$mode_label = $mode === 'scheduled' ? 'Scheduled' : 'Manual';

		// Check if switching to scheduled mode without a period configured
		if ($mode === 'scheduled') {
			$state = load_voting_state($config['voting_state_json']);
			if (empty($state['period']['start'])) {
				$_SESSION['flash'] = ['ok', "Mode changed to: $mode_label. Now configure a period to open the vote."];
			} else {
				$_SESSION['flash'] = ['ok', "Control mode changed to: $mode_label"];
			}
		} else {
			$_SESSION['flash'] = ['ok', "Control mode changed to: $mode_label"];
		}
	} else {
		$_SESSION['flash'] = ['err', 'Invalid mode.'];
	}
	header('Location: index.php#voting'); exit;
}

if ($is_admin && $action === 'set_period' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$start = $_POST['start'] ?? '';
	$end = $_POST['end'] ?? '';
	if (empty($start)) {
		$_SESSION['flash'] = ['err', 'Start date is required.'];
	} elseif (set_voting_period_with_optional_end($config['voting_state_json'], $start, $end)) {
		$_SESSION['flash'] = ['ok', 'Voting period configured successfully.'];
	} else {
		$_SESSION['flash'] = ['err', 'Configuration error. Verify that end date is after start date.'];
	}
	header('Location: index.php#voting'); exit;
}

if ($is_admin && $action === 'clear_period' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	if (clear_voting_period($config['voting_state_json'])) {
		$_SESSION['flash'] = ['ok', 'Voting period cleared.'];
	} else {
		$_SESSION['flash'] = ['err', 'Error during deletion.'];
	}
	header('Location: index.php#voting'); exit;
}

if ($is_admin && $action === 'set_override' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$override = $_POST['override'] ?? '';
	if ($override === 'open') {
		set_manual_override_with_tracking($config['voting_state_json'], true);
		$_SESSION['flash'] = ['ok', 'Voting opened.'];
	} elseif ($override === 'closed') {
		set_manual_override_with_tracking($config['voting_state_json'], false);
		$_SESSION['flash'] = ['ok', 'Voting closed.'];
	} elseif ($override === 'toggle') {
		$state = load_voting_state($config['voting_state_json']);
		$new_state = !($state['manual_override'] ?? false);
		set_manual_override_with_tracking($config['voting_state_json'], $new_state);
		$_SESSION['flash'] = ['ok', $new_state ? 'Voting opened.' : 'Voting closed.'];
	}
	header('Location: index.php#voting'); exit;
}

if ($is_admin && $action === 'clear_override' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	clear_manual_override($config['voting_state_json']);
	$_SESSION['flash'] = ['ok', 'Manual control disabled.'];
	header('Location: index.php#voting'); exit;
}

if ($is_admin && $action === 'close_scheduled_now' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$state = load_voting_state($config['voting_state_json']);
	if (($state['mode'] ?? '') !== 'scheduled') {
		$_SESSION['flash'] = ['err', 'Voting is not in scheduled mode.'];
	} elseif (empty($state['period']['start'])) {
		$_SESSION['flash'] = ['err', 'No scheduled period to close.'];
	} else {
		try {
			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$start = new DateTimeImmutable($state['period']['start'], new DateTimeZone('UTC'));
			$effective_end = $now < $start ? $start : $now;
			$state['period']['end'] = $effective_end->format('Y-m-d\TH:i:s\Z');
			if (save_json_file($config['voting_state_json'], $state)) {
				$_SESSION['flash'] = ['ok', 'Scheduled voting is now closed.'];
			} else {
				$_SESSION['flash'] = ['err', 'Unable to close the vote.'];
			}
		} catch (Exception $e) {
			$_SESSION['flash'] = ['err', 'Error closing the vote.'];
		}
	}
	header('Location: index.php#voting'); exit;
}

// Handle onboarding dismissal
if ($is_admin && $action === 'dismiss_onboarding' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	dismiss_onboarding($config, $_SESSION['admin_email']);
	http_response_code(204); // No content response
	exit;
}

// DEBUG: Erase all votes (only available in debug mode)
if ($is_admin && $action === 'debug_erase_votes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');

	// Security: Only allow in debug mode
	if (!is_debug_enabled()) {
		$_SESSION['flash'] = ['err', 'This action is only available in debug mode.'];
		header('Location: index.php'); exit;
	}

	$errors = [];
	$deleted_count = 0;

	// Delete all vote files
	$vote_files = glob($config['votes_dir'] . '/*.vote.enc');
	if ($vote_files !== false) {
		foreach ($vote_files as $file) {
			if (unlink($file)) {
				$deleted_count++;
			} else {
				$errors[] = 'Failed to delete: ' . basename($file);
			}
		}
	}

	// Delete voter mapping file if it exists
	$mapping_file = get_voter_mapping_path($config);
	if (file_exists($mapping_file)) {
		if (!unlink($mapping_file)) {
			$errors[] = 'Failed to delete voter mapping file';
		}
	}

	if (empty($errors)) {
		$_SESSION['flash'] = ['ok', "All votes erased ($deleted_count vote files deleted)."];
	} else {
		$_SESSION['flash'] = ['err', 'Partial deletion: ' . implode(', ', $errors)];
	}
	header('Location: index.php'); exit;
}

/* ===================== VIEWS ===================== */
// Using admin_header_html and admin_flash_html from admin_helpers.php

/* ===================== RENDER ===================== */
// Note: Login form removed - users must go to login.php first
// This page is 100% protected and only accessible to authenticated admins

// Load onboarding status for current admin
$onboarding_status = get_onboarding_status($config, $_SESSION['admin_email']);

admin_header_html('Admin Section', $config['app_name'], $config);
admin_flash_html($flash);

// Show warning if logged in via setup token
if (!empty($_SESSION['admin_via_setup_token'])):
?>
<div class="admin-alert warning" style="margin-bottom: 1.5rem;">
    <strong>You are using temporary admin access</strong><br>
    <p style="margin: 0.5rem 0;">
        Please configure your <a href="mail-settings/">email settings</a> and test that emails work.
        Then go to <a href="security-settings/">Security Settings</a> to disable temporary access.
    </p>
</div>
<?php endif; ?>
<h2 class="admin-main-title">Admin Panel</h2>

	<?php
	// Display onboarding banner if applicable
	include __DIR__ . '/includes/onboarding-banner.php';
	?>

	<!-- ===================== ADMIN NAVIGATION MENU ===================== -->
	<div class="admin-nav-menu">
		<h2 class="admin-section-header">Quick Actions</h2>
	<div class="admin-nav-cards">
		<a href="candidates/" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">👥</div>
				<h3>Candidate Management</h3>
			</div>
			<p>Add, edit, or remove candidates</p>
		</a>

		<a href="vote-settings/" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">⚙️</div>
				<h3>Vote Settings</h3>
			</div>
			<p>Configure voting rules (min/max, options)</p>
		</a>

		<a href="mail-settings/" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">📧</div>
				<h3>Mail & App Settings</h3>
			</div>
			<p>Set app name and email sending mode</p>
		</a>

		<a href="security-settings/" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">🔒</div>
				<h3>Security Settings</h3>
			</div>
			<p>Configure captcha and bot protection</p>
		</a>

		<a href="admin-emails/" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">🛡️</div>
				<h3>Admin Access</h3>
			</div>
			<p>Manage admin emails</p>
		</a>

		<a href="vote-key/" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">🔑</div>
				<h3>Vote Decryption Key</h3>
			</div>
			<p>Generate or verify encryption key</p>
		</a>

		<a href="../vote/" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">🗳️</div>
				<h3>Voting Page</h3>
			</div>
			<p>Access voting interface</p>
		</a>

		<a href="#download-results" class="admin-nav-card">
			<div class="admin-nav-card-header">
				<div class="admin-nav-card-icon">📥</div>
				<h3>Results export</h3>
			</div>
			<p>Jump to the download card at the bottom</p>
		</a>
	</div>
	</div>

  <?php
  // Check for X25519 public key presence
  $pubkey_file = __DIR__ . "/../../php-survey-backend/data/pubkey.txt";
  $pubkey_exists = file_exists($pubkey_file) && strlen(base64_decode(trim(file_get_contents($pubkey_file)), true)) === 32;
  if (!$pubkey_exists) {
      echo '<div class="admin-alert warning"><strong>Missing encryption key</strong><br>
      The public encryption key is not initialized for this project.<br><br>
      <a href="vote-key/index.php" target="_blank" rel="noopener">Generate key now (secure, browser-based)</a><br>
      <small class="muted">You will need to save the generated secret phrase. This operation should be done on a trusted device.</small></div>';
  }

  // Load voting status
  $voting_status = get_voting_status_extended($config['voting_state_json'], $config['votes_dir']);
  ?>

	<h2 class="admin-section-header">Voting Status</h2>
	<?php
	$voting_method = $config['voting_method'] ?? 'approval';
	$voting_method_label = $voting_method === 'stv' ? 'STV (Single Transferable Vote)' : 'Approval Voting';
	?>
	<div class="admin-participation">
		<strong>Status:</strong>
		<?php if ($voting_status['is_open']): ?>
			<span class="status-badge status-open">✓ OPEN</span>
		<?php else: ?>
			<span class="status-badge status-closed">✗ CLOSED</span>
		<?php endif; ?>
		<br>
		<strong>Voting method:</strong> <?= h($voting_method_label) ?>
		<br>
		<strong>Mode:</strong> <?= $voting_status['mode'] === 'manual' ? 'Manual' : 'Scheduled' ?>
		<br>
		<strong>Recorded votes:</strong> <?= $voting_status['vote_count'] ?>
		<br>
		<strong>Candidates locked:</strong> <?= $voting_status['candidates_locked'] ? 'Yes' : 'No' ?>

		<?php if ($voting_status['mode'] === 'manual'): ?>
			<br>
			<span class="muted">Manual control (use button to open/close)</span>
		<?php elseif ($voting_status['period_configured']): ?>
			<?php
			if ($voting_status['period_active']) {
				if (!empty($voting_status['period']['end'])) {
					$end = new DateTimeImmutable($voting_status['period']['end'], new DateTimeZone('UTC'));
					$end_label = h(format_program_mode_datetime($end));
					$end_iso = h($voting_status['period']['end']);
					echo '<br><span class="muted">Voting open until <span class="local-time" data-iso="' . $end_iso . '">' . $end_label . '</span></span>';
				} else {
					echo '<br><span class="muted">Voting open (no end date)</span>';
				}
			} elseif ($voting_status['period_future']) {
				$start = new DateTimeImmutable($voting_status['period']['start'], new DateTimeZone('UTC'));
				$start_label = h(format_program_mode_datetime($start));
				$start_iso = h($voting_status['period']['start']);
				echo '<br><span class="muted">Voting scheduled: <span class="local-time" data-iso="' . $start_iso . '">' . $start_label . '</span></span>';
			} else {
				echo '<br><span class="muted">Voting closed (period expired)</span>';
			}
			?>
		<?php else: ?>
			<br>
			<span class="muted">No period configured</span>
		<?php endif; ?>
	</div>

	<!-- ===================== OPENING AND CLOSING VOTING ===================== -->
	<div id="voting" class="admin-section">
		<h2 class="admin-section-header charcoal">Opening and Closing Voting</h2>

			<div class="admin-subsection">
				<h3>Control Mode</h3>
				<p class="muted">Manual mode allows opening/closing voting with a button. Scheduled mode uses an automatic period.</p>
				<form method="post" class="row admin-form" id="mode-selector-form">
					<input type="hidden" name="action" value="set_mode">
					<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
					<div class="mode-select-row">
						<label class="row">
							<span class="muted">Choose voting management mode</span>
							<select name="mode" id="voting-mode-select">
								<option value="scheduled" <?= $voting_status['mode'] === 'scheduled' ? 'selected' : '' ?>>Scheduled (automatic period)</option>
								<option value="manual" <?= $voting_status['mode'] === 'manual' ? 'selected' : '' ?>>Manual (manual open/close)</option>
							</select>
						</label>
						<button type="submit" id="voting-mode-submit" disabled>Validate my choice</button>
					</div>
				</form>
			</div>

		<?php if ($voting_status['mode'] === 'manual'): ?>
		<!-- Manual Mode UI -->
		<div id="manual-mode-section" class="admin-subsection">
			<h3>Manual Control</h3>
			<p class="muted">Open or close the vote manually with a button.</p>
			<form method="post" class="admin-form">
				<input type="hidden" name="action" value="set_override">
				<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
				<?php $is_currently_open = $voting_status['manual_override'] === true; ?>
				<button type="submit" name="override" value="toggle" class="admin-toggle-btn <?= $is_currently_open ? 'close' : 'open' ?>">
					<?= $is_currently_open ? '🔒 Close the Vote' : '🔓 Open the Vote' ?>
				</button>
			</form>
		</div>
		<?php else: ?>
			<!-- Scheduled Mode UI -->
			<div id="scheduled-mode-section" class="admin-subsection">
				<h3>Voting Period</h3>
				<?php if ($voting_status['period_configured']): ?>
					<?php
					$period_start_iso = $voting_status['period']['start'] ?? '';
					$period_end_iso = $voting_status['period']['end'] ?? '';
					$period_start_display = format_program_mode_iso($period_start_iso);
					$period_end_display = format_program_mode_iso($period_end_iso);
					?>
					<div class="admin-period-box">
						<strong>Configured period:</strong>
						<br>
						Start: <span class="local-time" data-iso="<?= h($period_start_iso) ?>"><?= h($period_start_display) ?></span>
						<br>
						<?php if (!empty($voting_status['period']['end'])): ?>
							End: <span class="local-time" data-iso="<?= h($period_end_iso) ?>"><?= h($period_end_display) ?></span>
						<?php else: ?>
							End: <em>None (open indefinitely after start)</em>
						<?php endif; ?>
						<?php if ($voting_status['period_active']): ?>
							<span class="period-status active">Voting is open.</span>
						<?php elseif ($voting_status['period_future']): ?>
							<span class="period-status future">Voting will open at the scheduled period.</span>
						<?php else: ?>
							<span class="period-status expired">Voting is closed.</span>
						<?php endif; ?>
					</div>
					<form method="post" class="admin-form">
						<input type="hidden" name="action" value="clear_period">
						<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
						<button type="submit" class="secondary" onclick="return confirm('Clear voting period?')">Clear period</button>
					</form>
				<?php else: ?>
					<p class="muted">No period configured.</p>
				<?php endif; ?>

				<h4><?= $voting_status['period_configured'] ? 'Modify period' : 'Configure period' ?></h4>
				<form method="post" class="row" id="period-form">
					<input type="hidden" name="action" value="set_period">
					<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

					<?php
					$start_value = '';
					$end_value = '';
					if ($voting_status['period_configured']) {
						try {
							$start_dt = new DateTimeImmutable($voting_status['period']['start']);
							$start_value = $start_dt->format('Y-m-d\TH:i');
							if (!empty($voting_status['period']['end'])) {
								$end_dt = new DateTimeImmutable($voting_status['period']['end']);
								$end_value = $end_dt->format('Y-m-d\TH:i');
							}
						} catch (Exception $e) {}
					}
					?>

					<label class="row">
						<span class="muted">Start date and time (your timezone)</span>
						<input type="datetime-local" name="start" id="start-date" required value="<?= h($start_value) ?>">
					</label>

					<label class="row">
						<span class="muted">End date and time (optional - leave blank for no end)</span>
						<input type="datetime-local" name="end" id="end-date" value="<?= h($end_value) ?>">
					</label>

				<button type="submit"><?= $voting_status['period_configured'] ? 'Update' : 'Configure' ?> period</button>
			</form>
			<p class="admin-form-note">Note: Dates are automatically converted to UTC. If you don't set an end date, voting will remain open indefinitely after the start.</p>
			<form method="post" class="admin-form danger-form" onsubmit="return confirm('Close the vote immediately?');">
				<input type="hidden" name="action" value="close_scheduled_now">
				<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
				<button type="submit" class="admin-danger-btn">Close the vote now</button>
			</form>
		</div>
		<?php endif; ?>
	</div>

	<!-- ===================== VOTERS ===================== -->
	<div id="voters" class="admin-section">
	<h2 class="admin-section-header slate">Voters</h2>
	<?php
	// Load voter emails and check who has voted
	$allowed_emails = [];
	if (file_exists($config['voter_emails'])) {
		$allowed_emails = file($config['voter_emails'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$allowed_emails = array_unique($allowed_emails);
	}

	$voted_list = [];
	$not_voted_count = 0;

	foreach ($allowed_emails as $email) {
		$email = trim($email);
		if (empty($email)) continue;

		if (has_voted($email, $config['votes_dir'], $config['secret_key'])) {
			$vote_file = get_vote_path($email, $config['votes_dir'], $config['secret_key']);
			$timestamp = file_exists($vote_file) ? filemtime($vote_file) : 0;
			$voted_list[] = [
				'email' => $email,
				'timestamp' => $timestamp,
				'date' => date('Y-m-d H:i:s', $timestamp)
			];
		} else {
			$not_voted_count++;
		}
	}

	// Sort by timestamp (most recent first)
	usort($voted_list, function($a, $b) {
		return $b['timestamp'] <=> $a['timestamp'];
	});

	$total = count($allowed_emails);
	$voted_count = count($voted_list);
	$visible_voters = array_slice($voted_list, 0, 10);
	$has_more_voters = $voted_count > count($visible_voters);
	$participation_rate = $total > 0 ? round($voted_count / $total * 100, 1) : 0;
	?>

	<div class="admin-participation">
		<strong>Participation:</strong> <?= $voted_count ?> / <?= $total ?> (<?= $participation_rate ?>%)
		<br>
		<span class="muted">Haven't voted yet: <?= $not_voted_count ?></span>
	</div>

	<?php
	// Enforce anonymity: hide voter details and export while voting is open in anonymous mode
	$anonymity_enforced = $config['anonymous_voter_export'] && $voting_status['is_open'];
	?>

	<?php if ($anonymity_enforced): ?>
		<p class="muted">Voter details and vote export are hidden while voting is open (anonymous mode enabled).</p>
	<?php else: ?>

	<?php if (!empty($voted_list)): ?>
		<table class="admin-table">
			<thead>
				<tr>
					<th>Email</th>
					<th>Vote date</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($visible_voters as $vote): ?>
					<tr>
						<td><?= h($vote['email']) ?></td>
						<td><?= h($vote['date']) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($has_more_voters): ?>
				<div class="admin-table-actions">
					<a class="admin-see-more" href="voters-list.php">See all voters</a>
				</div>
		<?php endif; ?>
	<?php else: ?>
		<p class="muted">No votes recorded yet.</p>
	<?php endif; ?>

	<div class="admin-download-section" id="download-results">
		<h2 class="admin-section-header slate">Vote results export</h2>
		<form method="get" id="download-csv-form" data-voting-open="<?= $voting_status['is_open'] ? 'true' : 'false' ?>">
			<input type="hidden" name="action" value="download_csv">
			<button type="submit" id="download-csv-btn">Download final results (CSV)</button>
		</form>
		<?php if ($config['anonymous_voter_export'] && has_voter_mapping($config)): ?>
		<style>
			.admin-mapping-section summary { display: flex; align-items: center; justify-content: space-between; }
			.admin-mapping-section .toggle-icon::before { content: '+'; }
			.admin-mapping-section[open] .toggle-icon::before { content: '-'; }
		</style>
		<details class="admin-mapping-section" style="margin-top: 20px; border: 1px solid #e0e0e0; border-radius: 8px; padding: 0;">
			<summary style="padding: 15px; cursor: pointer; background: #f8f9fa; border-radius: 8px; list-style: none;">
				<span style="display: flex; align-items: center; gap: 8px;">
					<strong>Voter Identity Mapping</strong>
					<?php if (has_mapping_pubkey($config)): ?>
					<span class="muted" style="font-size: 0.85em;">(separate encryption key)</span>
					<?php endif; ?>
				</span>
				<span class="toggle-icon" style="font-size: 1.2em; font-weight: bold; color: #666;"></span>
			</summary>
			<div style="padding: 15px; border-top: 1px solid #e0e0e0;">
				<div class="alert alert-warning" style="margin-bottom: 15px;">
					<strong>What this contains:</strong> Links between anonymous voter IDs and real email addresses.
				</div>
				<?php if (has_mapping_pubkey($config)): ?>
				<p class="muted" style="margin-bottom: 15px;">
					<strong>Separation of duties:</strong> This file is encrypted with a different key than the vote results.
					For maximum privacy, the mapping key should be held by a different person than the one who decrypts vote results.
				</p>
				<?php else: ?>
				<p class="muted" style="margin-bottom: 15px;">
					<strong>Note:</strong> No separate mapping key was configured. The mapping uses the same encryption key as the vote results.
					For better privacy, consider configuring a <a href="vote-key/#mapping-key">separate mapping key</a>.
				</p>
				<?php endif; ?>
				<form method="get" id="download-mapping-form">
					<input type="hidden" name="action" value="download_mapping">
					<button type="submit" id="download-mapping-btn" class="secondary">Download voter mapping (CSV)</button>
				</form>
			</div>
		</details>
		<?php endif; ?>
	</div>

	<?php endif; // end anonymity_enforced check ?>

	<div id="mnemonic-overlay" class="modal-overlay" hidden data-has-mapping-key="<?= ($config['anonymous_voter_export'] && has_mapping_pubkey($config)) ? 'true' : 'false' ?>">
		<div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mnemonic-title">
			<button type="button" class="modal-close" id="mnemonic-cancel-top" aria-label="Close">×</button>
				<h4 id="mnemonic-title">Decrypt CSV file</h4>
				<?php if ($voting_status['is_open']): ?>
				<div class="modal-warning" role="alert">
					<strong>Voting is still open.</strong>
					The downloaded CSV reflects live ballots and will change until voting is closed.
				</div>
				<?php endif; ?>
				<p id="mnemonic-instructions">
					This step requires the secret phrase generated during key initialization. Make sure you have it written down. You can open the initialization tool if you need to verify it.
				</p>
				<div id="mapping-key-notice" class="alert alert-info" style="display: none; margin-bottom: 15px;">
					<strong>Note:</strong> The voter mapping uses a separate encryption key.
					Enter the <em>mapping secret phrase</em>, not the vote results phrase.
				</div>
				<button type="button" class="button-link" id="open-mnemonic-page">Open initialization tool</button>
			<label for="mnemonic-input" class="modal-label">Your secret phrase (12 words)</label>
			<textarea id="mnemonic-input" placeholder="enter the 12 words separated by spaces" spellcheck="false" autocomplete="off"></textarea>
			<div id="mnemonic-error" class="modal-error" role="alert"></div>
			<div id="mnemonic-status" class="modal-status" role="status" aria-live="polite"></div>
			<div class="modal-actions">
				<button type="button" class="secondary" id="mnemonic-cancel">Cancel</button>
				<button type="button" id="mnemonic-confirm">Decrypt and download</button>
			</div>
		</div>
	</div>

	<script src="download.js?v=3"></script>
	</div><!-- end #voters -->

	<!-- Timezone handling script -->
		<script nonce="<?= h(csp_nonce()) ?>">
			document.addEventListener('DOMContentLoaded', function() {
				// Enable the mode submit button only when the selection changes
				const modeSelect = document.getElementById('voting-mode-select');
				const modeSubmit = document.getElementById('voting-mode-submit');
				if (modeSelect && modeSubmit) {
					const initialMode = modeSelect.value;
					const syncModeButtonState = () => {
						modeSubmit.disabled = (modeSelect.value === initialMode);
					};
					syncModeButtonState();
					modeSelect.addEventListener('change', syncModeButtonState);
				}

				// Convert UTC timestamps to local time for display
				document.querySelectorAll('.local-time').forEach(function(elem) {
					const text = elem.textContent;
					const dataIso = (elem.getAttribute('data-iso') || '').trim();
					const isoMatch = text.match(/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)/);
					const isoString = dataIso || (isoMatch ? isoMatch[1] : '');
					if (!isoString) return;

					const localDate = new Date(isoString);
					if (Number.isNaN(localDate.getTime())) return;

					const day = String(localDate.getDate()).padStart(2, '0');
					const monthMap = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
					const month = monthMap[localDate.getMonth()] || String(localDate.getMonth() + 1).padStart(2, '0');
					const year = localDate.getFullYear();
					const hours = String(localDate.getHours()).padStart(2, '0');
					const minutes = String(localDate.getMinutes()).padStart(2, '0');
					const offsetMinutes = -localDate.getTimezoneOffset();
					const offsetSign = offsetMinutes >= 0 ? '+' : '-';
					const offsetTotal = Math.abs(offsetMinutes);
					const offsetHours = String(Math.floor(offsetTotal / 60)).padStart(2, '0');
					const offsetMins = String(offsetTotal % 60).padStart(2, '0');
					const offsetLabel = `UTC${offsetSign}${offsetHours}:${offsetMins}`;
					const localStr = `${day} ${month} ${year} at ${hours}:${minutes} (${offsetLabel})`;

					if (dataIso) {
						elem.textContent = localStr;
					} else if (isoMatch) {
						elem.textContent = text.replace(isoMatch[1], localStr);
					}
				});

				// Handle form submission: convert local datetime to UTC
				const periodForm = document.getElementById('period-form');
				if (periodForm) {
					periodForm.addEventListener('submit', function(e) {
						const startInput = document.getElementById('start-date');
						const endInput = document.getElementById('end-date');

						if (startInput && startInput.value) {
							// Convert local datetime to UTC
							const localDate = new Date(startInput.value);
							const utcStr = localDate.toISOString().slice(0, 16); // Format: YYYY-MM-DDTHH:mm
							startInput.value = utcStr;
						}

						if (endInput && endInput.value) {
							const localDate = new Date(endInput.value);
							const utcStr = localDate.toISOString().slice(0, 16);
							endInput.value = utcStr;
						}
					});

					// When loading the form with existing values, convert UTC to local
					const startInput = document.getElementById('start-date');
					const endInput = document.getElementById('end-date');

					if (startInput && startInput.value) {
						// The value is in UTC, convert to local for display
						const utcDate = new Date(startInput.value + 'Z'); // Append Z to indicate UTC
						const localStr = new Date(utcDate.getTime() - utcDate.getTimezoneOffset() * 60000)
							.toISOString().slice(0, 16);
						startInput.value = localStr;
					}

					if (endInput && endInput.value) {
						const utcDate = new Date(endInput.value + 'Z');
						const localStr = new Date(utcDate.getTime() - utcDate.getTimezoneOffset() * 60000)
							.toISOString().slice(0, 16);
						endInput.value = localStr;
					}
				}
			});
		</script>

<?php if (is_debug_enabled()): ?>
<!-- DEBUG PANEL: Only visible when DEBUG_MODE=true and on localhost -->
<div class="admin-section" id="debug" style="background: #fff3cd; border: 2px dashed #ffc107; border-radius: 8px; padding: 20px; margin-top: 30px;">
	<h2 class="admin-section-header" style="color: #856404;">⚠️ Debug Mode</h2>
	<p class="muted" style="color: #856404;">These actions are only available in debug mode (localhost + DEBUG_MODE=true).</p>

	<div style="margin-top: 15px;">
		<h3 style="font-size: 1rem; margin-bottom: 10px;">Erase All Votes</h3>
		<p class="muted" style="margin-bottom: 10px;">Delete all vote files and voter mapping. This cannot be undone.</p>
		<form method="POST" action="?action=debug_erase_votes" onsubmit="return confirm('Are you sure you want to erase ALL votes? This cannot be undone.');">
			<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
			<button type="submit" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
				Erase All Votes
			</button>
		</form>
	</div>
</div>
<?php endif; ?>

<?php
// Footer links removed - now using the navigation menu cards above
$footer_links = [];
$is_admin_page = true; // Tell footer we don't have flow-stack

include __DIR__ . '/../footer.php';
?>
