<?php
declare(strict_types=1);

// Load admin helpers (includes security.php, helpers.php, and admin-specific functions)
require_once __DIR__ . '/../../../php-survey-backend/includes/admin_helpers.php';

// Initialize secure session
init_secure_session();

// Configure error logging and rotate logs
$log_dir = __DIR__ . '/../../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

/* ===================== LOAD CONFIG ===================== */
$config_path = __DIR__ . '/../../../php-survey-backend/config/config.php';
if (!file_exists($config_path)) { http_response_code(500); exit('Critical Error: Configuration file not found.'); }
$config = require $config_path;

/* ======== SECURITY: TRUSTED HOSTS + FIXED BASE URL ======== */
$trusted = $config['trusted_hosts'] ?? [];
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($trusted && !in_array($host, $trusted, true)) { http_response_code(400); exit('Unauthorized host'); }
$base_url = resolve_base_url($config);

/* ===================== SECURITY: ADMIN GUARD (STRICT) ===================== */
require_once __DIR__ . '/../../../php-survey-backend/includes/admin_guard_strict.php';

/* ======== SECRET GUARD ======== */
require_configured_secret($config);

/* ======== ENSURE DATA DIR ======== */
if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0750, true) && !is_dir($config['data_dir'])) {
	http_response_code(500); exit('Critical Error: Unable to create data directory.');
}

// Shared CSP for admin console (allows Turnstile + Quill/CDN assets)
send_default_csp($config);

/* ===================== ADMIN ALLOWED ===================== */
$admin_emails_path = __DIR__ . '/../../../php-survey-backend/config/admin_emails.txt';
$admin_allowed = function(string $email) use ($admin_emails_path): bool {
	if (!file_exists($admin_emails_path)) return false;
	$lines = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
	foreach ($lines as $line) {
		if (strtolower(trim($line)) === strtolower($email)) return true;
	}
	return false;
};

// Note: Admin authentication now handled by admin_guard_strict.php (line 47)
// The following code is only executed for authenticated admins

/* ===================== ROUTER ===================== */
$action = $_POST['action'] ?? 'view';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

if ($action === 'disconnect') {
	require_csrf($_POST['csrf'] ?? '');
	unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
	$_SESSION['flash'] = ['ok', 'Disconnected.'];
	header('Location: ' . $base_url); exit;
}

/* ===================== VOTE PARAMETERS ACTIONS ===================== */
if ($action === 'update_vote_params' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');

	$params_file = $config['vote_params_json'];
	$voting_method = $_POST['voting_method'] ?? 'approval';
	$min_picks = (int)($_POST['min_picks'] ?? 3);
	$max_picks = (int)($_POST['max_picks'] ?? 9);
	$stv_require_full = $_POST['stv_require_full_ranking'] ?? 'false';
	$stv_min_rankings = (int)($_POST['stv_min_rankings'] ?? 1);
	$allow_multiple = $_POST['allow_multiple_votes'] ?? 'false';
	$include_names = $_POST['include_candidate_names_in_receipt'] ?? 'false';
	$show_manifestos = $_POST['show_manifestos'] ?? 'true';
	$anonymous_export = $_POST['anonymous_voter_export'] ?? 'false';

	// Validate voting method
	if (!in_array($voting_method, ['approval', 'stv'], true)) {
		$voting_method = 'approval';
	}

	// Check if voting has started (candidates locked)
	$locked = are_candidates_locked($config['votes_dir']);

	if ($locked) {
		// If votes have started, only allow updating non-critical options
		$current_params = load_vote_params($params_file);
		$include_names_bool = ($include_names === 'true');

		// Check if critical parameters are being changed
		$critical_changed = (
			($current_params['voting_method'] ?? 'approval') !== $voting_method ||
			$current_params['min_picks'] != $min_picks ||
			$current_params['max_picks'] != $max_picks ||
			($current_params['stv_require_full_ranking'] ?? false) != ($stv_require_full === 'true') ||
			($current_params['stv_min_rankings'] ?? 1) != $stv_min_rankings ||
			$current_params['allow_multiple_votes'] != ($allow_multiple === 'true') ||
			$current_params['anonymous_voter_export'] != ($anonymous_export === 'true')
		);

		if ($critical_changed) {
			$_SESSION['flash'] = ['err', 'Vote parameters (voting method, min/max, STV settings, multiple votes, anonymous export) cannot be modified after voting has started. Only the email and manifesto options can be changed.'];
			header('Location: index.php'); exit;
		}

		// Only update the email option and manifesto visibility (non-critical settings)
		$current_params['include_candidate_names_in_receipt'] = $include_names_bool;
		$current_params['show_manifestos'] = ($show_manifestos === 'true');
		$current_params['last_configured_at'] = date('Y-m-d H:i:s');

		if (save_vote_params($params_file, $current_params)) {
			$_SESSION['flash'] = ['ok', 'Settings updated successfully.'];
		} else {
			$_SESSION['flash'] = ['err', 'Update error.'];
		}
		header('Location: index.php'); exit;
	}

	// No votes yet - full validation and update
	// Load candidates to validate against actual count
	$candidates = load_candidates($config['candidates_json']);
	$num_candidates = count($candidates);

	// Validation for Approval Voting
	if ($voting_method === 'approval') {
		if ($min_picks < 1 || $min_picks > 50) {
			$_SESSION['flash'] = ['err', 'Minimum number of choices must be between 1 and 50.'];
			header('Location: index.php'); exit;
		}

		if ($max_picks < $min_picks) {
			$_SESSION['flash'] = ['err', 'Maximum number must be greater than or equal to minimum.'];
			header('Location: index.php'); exit;
		}

		if ($max_picks > 50) {
			$_SESSION['flash'] = ['err', 'Maximum number cannot exceed 50.'];
			header('Location: index.php'); exit;
		}

		// Validate max_picks against number of candidates
		if ($num_candidates > 0 && $max_picks > $num_candidates) {
			$_SESSION['flash'] = ['err', "Maximum number of choices ($max_picks) cannot exceed the number of available candidates ($num_candidates)."];
			header('Location: index.php'); exit;
		}
	}

	// Validation for STV
	if ($voting_method === 'stv') {
		if ($stv_min_rankings < 1) {
			$stv_min_rankings = 1;
		}
		if ($num_candidates > 0 && $stv_min_rankings > $num_candidates) {
			$_SESSION['flash'] = ['err', "Minimum rankings ($stv_min_rankings) cannot exceed the number of available candidates ($num_candidates)."];
			header('Location: index.php'); exit;
		}
	}

	$allow_multiple_bool = ($allow_multiple === 'true');
	$include_names_bool = ($include_names === 'true');
	$anonymous_export_bool = ($anonymous_export === 'true');
	$stv_require_full_bool = ($stv_require_full === 'true');

	// Update vote_params.json file
	$params = [
		'voting_method' => $voting_method,
		'min_picks' => $min_picks,
		'max_picks' => $max_picks,
		'stv_require_full_ranking' => $stv_require_full_bool,
		'stv_min_rankings' => $stv_min_rankings,
		'allow_multiple_votes' => $allow_multiple_bool,
		'include_candidate_names_in_receipt' => $include_names_bool,
		'show_manifestos' => ($show_manifestos === 'true'),
		'anonymous_voter_export' => $anonymous_export_bool,
		'last_configured_at' => date('Y-m-d H:i:s')
	];

	if (save_vote_params($params_file, $params)) {
		$_SESSION['flash'] = ['ok', 'Vote parameters updated successfully.'];
	} else {
		$_SESSION['flash'] = ['err', 'Error updating parameters.'];
	}
	header('Location: index.php'); exit;
}

/* ===================== RENDER ===================== */
admin_header_html('Vote Settings', $config['app_name'], $config, '../../assets/css/style.css?v=6', '../../assets/css/admin.css?v=6');
admin_flash_html($flash);
?>

<div class="back-link">
	<a href="../index.php">Back to Admin Panel</a>
</div>

<?php
// Load candidates to show count and validate max_picks
$candidates = load_candidates($config['candidates_json']);
$num_candidates = count($candidates);
$max_allowed = $num_candidates > 0 ? min($num_candidates, 50) : 50;
?>

<?php
$is_stv = ($config['voting_method'] ?? 'approval') === 'stv';
$voting_method_label = $is_stv ? 'STV (Single Transferable Vote)' : 'Approval Voting';
?>
<div class="admin-info-box">
	<strong>Current configuration:</strong>
	<div class="info-item">
		<span class="info-label">Voting method:</span>
		<span class="info-value"><?= $voting_method_label ?></span>
	</div>
	<div class="info-item">
		<span class="info-label">Available candidates:</span>
		<span class="info-value"><?= $num_candidates ?></span>
	</div>
	<?php if (!$is_stv): ?>
	<div class="info-item">
		<span class="info-label">Minimum choices:</span>
		<span class="info-value"><?= $config['min_picks'] ?></span>
	</div>
	<div class="info-item">
		<span class="info-label">Maximum choices:</span>
		<span class="info-value"><?= $config['max_picks'] ?></span>
	</div>
	<?php else: ?>
	<div class="info-item">
		<span class="info-label">Full ranking required:</span>
		<span class="info-value"><?= $config['stv_require_full_ranking'] ? 'Yes' : 'No' ?></span>
	</div>
	<?php if (!$config['stv_require_full_ranking']): ?>
	<div class="info-item">
		<span class="info-label">Minimum rankings:</span>
		<span class="info-value"><?= $config['stv_min_rankings'] ?></span>
	</div>
	<?php endif; ?>
	<?php endif; ?>
	<div class="info-item">
		<span class="info-label">Multiple votes:</span>
		<span class="info-value"><?= $config['allow_multiple_votes'] ? 'Allowed' : 'Not allowed' ?></span>
	</div>
	<div class="info-item">
		<span class="info-label">Names in email:</span>
		<span class="info-value"><?= $config['include_candidate_names_in_receipt'] ? 'Yes' : 'No' ?></span>
	</div>
	<div class="info-item">
		<span class="info-label">Show manifestos:</span>
		<span class="info-value"><?= $config['show_manifestos'] ? 'Yes' : 'No' ?></span>
	</div>
	<div class="info-item">
		<span class="info-label">Anonymous voter export:</span>
		<span class="info-value"><?= $config['anonymous_voter_export'] ? 'Yes' : 'No' ?></span>
	</div>
</div>

<h2 class="admin-section-header">Vote Settings</h2>

<?php
$locked = are_candidates_locked($config['votes_dir']);
if ($locked):
?>
	<div class="admin-alert warning">
		<strong>⚠️ Restricted modification</strong><br>
		Votes have already been recorded. Vote parameters (min/max, multiple votes) are locked to preserve result consistency.<br>
		<small>The option to include names in the email remains modifiable.</small>
	</div>
<?php endif; ?>

<form method="post" class="row admin-form" id="vote-params-form">
	<input type="hidden" name="action" value="update_vote_params">
	<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

	<label class="row">
		<span class="muted">Voting method</span>
		<select name="voting_method" id="voting-method-select"<?= $locked ? ' disabled' : '' ?>>
			<option value="approval" <?= ($config['voting_method'] ?? 'approval') === 'approval' ? 'selected' : '' ?>>Approval Voting - Select multiple candidates</option>
			<option value="stv" <?= ($config['voting_method'] ?? 'approval') === 'stv' ? 'selected' : '' ?>>STV (Single Transferable Vote) - Rank candidates</option>
		</select>
		<small class="muted">Approval: voters select multiple candidates (all selections equal). STV: voters rank candidates in order of preference.</small>
	</label>

	<div id="approval-options"<?= $is_stv ? ' style="display:none;"' : '' ?>>
	<label class="row">
		<span class="muted">Minimum number of choices required</span>
		<div class="number-input-wrapper">
			<input type="number" id="min-picks" name="min_picks" min="1" max="<?= $max_allowed ?>" value="<?= h((string)$config['min_picks']) ?>"<?= $locked ? ' disabled' : '' ?>>
			<?php if (!$locked): ?>
			<div class="number-stepper">
				<button type="button" class="stepper-up" data-target="min-picks" aria-label="Increase">▲</button>
				<button type="button" class="stepper-down" data-target="min-picks" aria-label="Decrease">▼</button>
			</div>
			<?php endif; ?>
		</div>
	</label>

	<label class="row">
		<span class="muted">Maximum number of choices allowed<?= $num_candidates > 0 ? " (max: $num_candidates candidates)" : '' ?></span>
		<div class="number-input-wrapper">
			<input type="number" id="max-picks" name="max_picks" min="1" max="<?= $max_allowed ?>" value="<?= h((string)$config['max_picks']) ?>"<?= $locked ? ' disabled' : '' ?>>
			<?php if (!$locked): ?>
			<div class="number-stepper">
				<button type="button" class="stepper-up" data-target="max-picks" aria-label="Increase">▲</button>
				<button type="button" class="stepper-down" data-target="max-picks" aria-label="Decrease">▼</button>
			</div>
			<?php endif; ?>
		</div>
	</label>
	</div><!-- /approval-options -->

	<div id="stv-options"<?= !$is_stv ? ' style="display:none;"' : '' ?>>
	<label class="row">
		<span class="muted">Require full ranking</span>
		<select name="stv_require_full_ranking" id="stv-require-full"<?= $locked ? ' disabled' : '' ?>>
			<option value="false" <?= !($config['stv_require_full_ranking'] ?? false) ? 'selected' : '' ?>>No - Partial ranking allowed</option>
			<option value="true" <?= ($config['stv_require_full_ranking'] ?? false) ? 'selected' : '' ?>>Yes - Must rank all candidates</option>
		</select>
		<small class="muted">When partial ranking is allowed, voters can rank only their preferred candidates.</small>
	</label>

	<label class="row" id="stv-min-rankings-row"<?= ($config['stv_require_full_ranking'] ?? false) ? ' style="display:none;"' : '' ?>>
		<span class="muted">Minimum rankings required<?= $num_candidates > 0 ? " (max: $num_candidates candidates)" : '' ?></span>
		<div class="number-input-wrapper">
			<input type="number" id="stv-min-rankings" name="stv_min_rankings" min="1" max="<?= $max_allowed ?>" value="<?= h((string)($config['stv_min_rankings'] ?? 1)) ?>"<?= $locked ? ' disabled' : '' ?>>
			<?php if (!$locked): ?>
			<div class="number-stepper">
				<button type="button" class="stepper-up" data-target="stv-min-rankings" aria-label="Increase">▲</button>
				<button type="button" class="stepper-down" data-target="stv-min-rankings" aria-label="Decrease">▼</button>
			</div>
			<?php endif; ?>
		</div>
		<small class="muted">Minimum number of candidates the voter must rank.</small>
	</label>
	</div><!-- /stv-options -->

	<label class="row">
		<span class="muted">Allow multiple votes</span>
		<select name="allow_multiple_votes"<?= $locked ? ' disabled' : '' ?>>
			<option value="false" <?= !$config['allow_multiple_votes'] ? 'selected' : '' ?>>No - Only one vote per voter</option>
			<option value="true" <?= $config['allow_multiple_votes'] ? 'selected' : '' ?>>Yes - Voters can change their vote</option>
		</select>
		<small class="muted">When enabled, voters can submit a new vote that replaces their previous one. Their old vote is overwritten, not accumulated.</small>
	</label>

	<label class="row">
		<span class="muted">Include chosen candidate names in voters' confirmation email</span>
		<select name="include_candidate_names_in_receipt">
			<option value="false" <?= !$config['include_candidate_names_in_receipt'] ? 'selected' : '' ?>>No - Generic message only</option>
			<option value="true" <?= $config['include_candidate_names_in_receipt'] ? 'selected' : '' ?>>Yes - Include candidate names</option>
		</select>
		<?php if (!$locked): ?>
		<small class="muted">This option can be modified at any time, even after voting begins.</small>
		<?php else: ?>
		<small class="muted">✓ This option can be modified at any time (only affects future emails).</small>
		<?php endif; ?>
	</label>

	<label class="row">
		<span class="muted">Show candidate manifestos to voters</span>
		<select name="show_manifestos">
			<option value="true" <?= $config['show_manifestos'] ? 'selected' : '' ?>>Yes - Show manifestos</option>
			<option value="false" <?= !$config['show_manifestos'] ? 'selected' : '' ?>>No - Hide all manifestos</option>
		</select>
		<small class="muted">When disabled, the "Read manifest" button will be hidden for all candidates.</small>
	</label>

	<label class="row">
		<span class="muted">Anonymous voter export (enhanced privacy)</span>
		<select name="anonymous_voter_export"<?= $locked ? ' disabled' : '' ?>>
			<option value="false" <?= !$config['anonymous_voter_export'] ? 'selected' : '' ?>>No - Voter emails included in results</option>
			<option value="true" <?= $config['anonymous_voter_export'] ? 'selected' : '' ?>>Yes - Replace emails with anonymous IDs</option>
		</select>
		<small class="muted">When enabled, voter emails are stored in a separate encrypted file and not included in the main results export. Only admins with server access can download the mapping.</small>
	</label>

	<button type="submit" id="submit-params-btn">
		Save parameters
	</button>
</form>

<p class="admin-form-note">
	<strong>Note:</strong> These parameters determine how many candidates voters must/can select.
		<?php if ($num_candidates > 0): ?>
		The maximum is limited to the number of available candidates (<?= $num_candidates ?>).
		<?php endif; ?>
</p>

<!-- ===================== ANNOUNCEMENT REDIRECT ===================== -->
<div id="announcement-link" class="admin-section">
	<h2 class="admin-section-header">Announcement Configuration</h2>
	<p class="muted">
		Go to the dedicated page to configure the announcement displayed to voters:
		<a class="inline-admin-link" href="../announcement-settings/index.php">Open the announcement page</a>
	</p>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
// Client-side validation for vote parameters
document.addEventListener('DOMContentLoaded', function() {
	const form = document.getElementById('vote-params-form');
	if (!form) return;

	const minInput = document.getElementById('min-picks');
	const maxInput = document.getElementById('max-picks');
	const votingMethodSelect = document.getElementById('voting-method-select');
	const approvalOptions = document.getElementById('approval-options');
	const stvOptions = document.getElementById('stv-options');
	const stvRequireFullSelect = document.getElementById('stv-require-full');
	const stvMinRankingsRow = document.getElementById('stv-min-rankings-row');
	const stvMinRankingsInput = document.getElementById('stv-min-rankings');

	// Store original disabled state (from PHP $locked)
	const minInputLocked = minInput ? minInput.disabled : false;
	const maxInputLocked = maxInput ? maxInput.disabled : false;

	// Show/hide options based on voting method
	function updateVotingMethodOptions() {
		if (!votingMethodSelect || !approvalOptions || !stvOptions) return;
		const isStv = votingMethodSelect.value === 'stv';
		approvalOptions.style.display = isStv ? 'none' : '';
		stvOptions.style.display = isStv ? '' : 'none';

		// Disable hidden fields to exclude them from form validation entirely
		// Preserve original locked state when showing approval options
		if (minInput) minInput.disabled = isStv || minInputLocked;
		if (maxInput) maxInput.disabled = isStv || maxInputLocked;
	}

	// Show/hide min rankings based on require full ranking
	function updateStvMinRankingsVisibility() {
		if (!stvRequireFullSelect || !stvMinRankingsRow) return;
		const requireFull = stvRequireFullSelect.value === 'true';
		stvMinRankingsRow.style.display = requireFull ? 'none' : '';
	}

	if (votingMethodSelect) {
		votingMethodSelect.addEventListener('change', updateVotingMethodOptions);
	}
	if (stvRequireFullSelect) {
		stvRequireFullSelect.addEventListener('change', updateStvMinRankingsVisibility);
	}

	function validateMinMax() {
		if (!minInput || !maxInput) return;
		const minVal = parseInt(minInput.value);
		const maxVal = parseInt(maxInput.value);

		if (minVal && maxVal && minVal > maxVal) {
			maxInput.setCustomValidity('The maximum must be greater than or equal to the minimum (' + minVal + ')');
		} else {
			maxInput.setCustomValidity('');
		}
	}

	if (minInput) minInput.addEventListener('input', validateMinMax);
	if (maxInput) maxInput.addEventListener('input', validateMinMax);

	form.addEventListener('submit', function(e) {
		// Only validate min/max if approval voting is selected AND inputs are not disabled
		if (votingMethodSelect && votingMethodSelect.value === 'approval' && minInput && !minInput.disabled) {
			validateMinMax();
		}
		if (!form.checkValidity()) {
			e.preventDefault();
			form.reportValidity();
		}
	});

	// Stepper button functionality
	const stepperButtons = document.querySelectorAll('.number-stepper button');
	stepperButtons.forEach(button => {
		button.addEventListener('click', function(e) {
			e.preventDefault();
			const targetId = this.getAttribute('data-target');
			const input = document.getElementById(targetId);
			if (!input) return;

			const min = parseInt(input.getAttribute('min')) || 1;
			const max = parseInt(input.getAttribute('max')) || 999;
			let currentValue = parseInt(input.value) || min;

			if (this.classList.contains('stepper-up')) {
				if (currentValue < max) {
					input.value = currentValue + 1;
					input.dispatchEvent(new Event('input', { bubbles: true }));
				}
			} else if (this.classList.contains('stepper-down')) {
				if (currentValue > min) {
					input.value = currentValue - 1;
					input.dispatchEvent(new Event('input', { bubbles: true }));
				}
			}
		});
	});

	// Update stepper button states based on input value
	function updateStepperStates() {
		const inputs = [minInput, maxInput, stvMinRankingsInput].filter(Boolean);
		inputs.forEach(input => {
			const wrapper = input.closest('.number-input-wrapper');
			if (!wrapper) return;

			const upButton = wrapper.querySelector('.stepper-up');
			const downButton = wrapper.querySelector('.stepper-down');
			if (!upButton || !downButton) return;

			const min = parseInt(input.getAttribute('min')) || 1;
			const max = parseInt(input.getAttribute('max')) || 999;
			const currentValue = parseInt(input.value) || min;

			downButton.disabled = currentValue <= min;
			upButton.disabled = currentValue >= max;
		});
	}

	if (minInput) minInput.addEventListener('input', updateStepperStates);
	if (maxInput) maxInput.addEventListener('input', updateStepperStates);
	if (stvMinRankingsInput) stvMinRankingsInput.addEventListener('input', updateStepperStates);

	// Initialize on page load
	updateVotingMethodOptions();
	updateStepperStates();
});

</script>

<?php
$is_admin_page = true; // Tell footer we don't have flow-stack
include __DIR__ . '/../../footer.php';
?>
