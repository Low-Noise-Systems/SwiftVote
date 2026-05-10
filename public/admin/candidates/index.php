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

/* ===================== ROUTER ===================== */
$action = $_GET['action'] ?? $_POST['action'] ?? 'candidates';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

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

$is_admin = false;
if (isset($_SESSION['admin_email']) && $admin_allowed($_SESSION['admin_email'])) {
	$is_admin = true;
}

/* ===================== CANDIDATE MANAGEMENT ACTIONS ===================== */
if ($is_admin && $action === 'add_candidate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$locked = are_candidates_locked($config['votes_dir']);
	if ($locked) {
		$_SESSION['flash'] = ['err', 'Cannot modify candidates: votes have already been recorded.'];
		header('Location: index.php'); exit;
	}
	$name = trim($_POST['name'] ?? '');
	$linkedin = trim($_POST['linkedin'] ?? '');
	$statement = trim($_POST['statement'] ?? '');
	$manifesto_enabled = isset($_POST['manifesto_enabled']) && $_POST['manifesto_enabled'] === '1';
	if (empty($name)) {
		$_SESSION['flash'] = ['err', 'Candidate name is required.'];
		header('Location: index.php'); exit;
	}
	$id = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
	if (empty($id)) $id = 'candidate_' . bin2hex(random_bytes(4));
	$data = load_candidates_data($config['candidates_json']);
	foreach ($data['candidates'] as $candidate) {
		if ($candidate['id'] === $id) {
			$id .= '_' . bin2hex(random_bytes(2));
			break;
		}
	}
	$data['candidates'][] = ['id' => $id, 'name' => $name, 'linkedin' => $linkedin, 'statement' => $statement, 'manifesto_enabled' => $manifesto_enabled];
	if (save_candidates_data($config['candidates_json'], $data)) {
		$_SESSION['flash'] = ['ok', 'Candidate added successfully.'];
	} else {
		$_SESSION['flash'] = ['err', 'Error adding candidate.'];
	}
	header('Location: index.php'); exit;
}

if ($is_admin && $action === 'edit_candidate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$locked = are_candidates_locked($config['votes_dir']);
	if ($locked) {
		$_SESSION['flash'] = ['err', 'Cannot modify candidates: votes have already been recorded.'];
		header('Location: index.php'); exit;
	}
	$id = $_POST['id'] ?? '';
	$name = trim($_POST['name'] ?? '');
	$linkedin = trim($_POST['linkedin'] ?? '');
	$statement = trim($_POST['statement'] ?? '');
	$manifesto_enabled = isset($_POST['manifesto_enabled']) && $_POST['manifesto_enabled'] === '1';
	if (empty($id) || empty($name)) {
		$_SESSION['flash'] = ['err', 'ID and name required.'];
		header('Location: index.php'); exit;
	}
	$data = load_candidates_data($config['candidates_json']);
	$found = false;
	foreach ($data['candidates'] as &$candidate) {
		if ($candidate['id'] === $id) {
			$candidate['name'] = $name;
			$candidate['linkedin'] = $linkedin;
			$candidate['statement'] = $statement;
			$candidate['manifesto_enabled'] = $manifesto_enabled;
			$found = true;
			break;
		}
	}
	unset($candidate);
	if ($found && save_candidates_data($config['candidates_json'], $data)) {
		$_SESSION['flash'] = ['ok', 'Candidate modified successfully.'];
	} else {
		$_SESSION['flash'] = ['err', 'Error during modification.'];
	}
	header('Location: index.php'); exit;
}

if ($is_admin && $action === 'delete_candidate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	require_csrf($_POST['csrf'] ?? '');
	$locked = are_candidates_locked($config['votes_dir']);
	if ($locked) {
		$_SESSION['flash'] = ['err', 'Cannot delete candidates: votes have already been recorded.'];
		header('Location: index.php'); exit;
	}
	$id = $_POST['id'] ?? '';
	if (empty($id)) {
		$_SESSION['flash'] = ['err', 'ID required.'];
		header('Location: index.php'); exit;
	}
	$data = load_candidates_data($config['candidates_json']);
	$data['candidates'] = array_values(array_filter($data['candidates'], function($c) use ($id) {
		return $c['id'] !== $id;
	}));
	if (save_candidates_data($config['candidates_json'], $data)) {
		$_SESSION['flash'] = ['ok', 'Candidate deleted successfully.'];
	} else {
		$_SESSION['flash'] = ['err', 'Error during deletion.'];
	}
	header('Location: index.php'); exit;
}

// Handle disconnect action
if ($action === 'disconnect') {
	// Clear both admin and voter sessions for security
	unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
	$_SESSION['flash'] = ['ok', 'Disconnected.'];
	header('Location: ' . $base_url); exit;
}

/* ===================== RENDER ===================== */
// Custom header for this page (includes Quill editor CSS)
echo '<!doctype html><html><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<meta name="robots" content="noindex, nofollow">';
echo '<title>' . h('Candidate Management') . '</title>';
echo '<link rel="stylesheet" href="../../assets/css/style.css?v=2">';
echo '<link rel="stylesheet" href="../../assets/css/admin.css?v=2">';
echo '<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">';
echo '</head><body><div class="main-wrapper"><div class="card">';
echo '<h1>' . h($config['app_name']) . '</h1>';
?>
<div class="back-link"><a href="../">Back to Admin Panel</a></div>
<?php
admin_flash_html($flash);

$verified = $_SESSION['admin_email'] ?? null;

if (!$is_admin): ?>
  <h2>Restricted Access</h2>
  <p>This page is reserved for administrators. Please log in via the <a href="../admin/">administration page</a>.</p>

<?php else: ?>
	<h2>Candidate Management</h2>

	<?php
	$candidates_data = load_candidates_data($config['candidates_json']);
	$candidates = $candidates_data['candidates'];
	$locked = are_candidates_locked($config['votes_dir']);
	$vote_count = count_votes($config['votes_dir']);
	?>

	<?php if ($locked): ?>
		<div class="admin-alert warning">
			<strong>🔒 List locked</strong>
			<br>
			Candidates cannot be modified because <?= $vote_count ?> vote(s) have already been recorded.
		</div>
	<?php else: ?>
		<div class="admin-info-box candidates-success-box">
			<strong>✓ Editing allowed</strong>
			<br>
			No votes recorded. You can add, edit, or delete candidates.
		</div>
	<?php endif; ?>

	<?php if (!empty($candidates)): ?>
		<table id="candidates-table" class="admin-table">
			<thead>
				<tr>
					<th>ID</th>
					<th>Name</th>
					<th>LinkedIn</th>
					<th>Manifest</th>
					<?php if (!$locked): ?><th>Actions</th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($candidates as $index => $candidate): ?>
				<tr class="candidate-row" id="row-<?= $index ?>">
					<td><code><?= h($candidate['id']) ?></code></td>
					<td><?= h($candidate['name']) ?></td>
					<td>
						<?php if (!empty($candidate['linkedin'])): ?>
							<a href="<?= h($candidate['linkedin']) ?>" target="_blank" rel="noopener">Profile</a>
						<?php else: ?>
							<span class="muted">—</span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$statement_preview = trim($candidate['statement'] ?? '');
						$enabled = $candidate['manifesto_enabled'] ?? true;
						if ($statement_preview !== '') {
							$preview_text = function_exists('mb_strimwidth')
								? mb_strimwidth($statement_preview, 0, 80, '…', 'UTF-8')
								: (strlen($statement_preview) > 80 ? substr($statement_preview, 0, 77) . '…' : $statement_preview);
							$status = $enabled ? '✓' : '✗ Hidden';
							?>
							<span class="muted"><?= h($preview_text) ?></span> <small>(<?= $status ?>)</small>
						<?php } else { ?>
							<span class="muted">—</span>
						<?php } ?>
					</td>
					<?php if (!$locked): ?>
						<td>
							<button type="button" class="secondary candidate-edit-btn" data-edit-index="<?= $index ?>">Edit</button>
							<form method="post" class="candidate-delete-form">
								<input type="hidden" name="action" value="delete_candidate">
								<input type="hidden" name="id" value="<?= h($candidate['id']) ?>">
								<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
								<button type="submit" class="secondary candidate-edit-btn" data-confirm="Delete this candidate?">Delete</button>
							</form>
						</td>
					<?php endif; ?>
				</tr>
				<?php if (!$locked): ?>
					<tr class="edit-form candidate-edit-row" id="edit-<?= $index ?>">
						<td colspan="5">
							<form method="post" class="row candidate-edit-form">
								<input type="hidden" name="action" value="edit_candidate">
								<input type="hidden" name="id" value="<?= h($candidate['id']) ?>">
								<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

								<label class="row">
									<span class="muted">Name</span>
									<input type="text" name="name" value="<?= h($candidate['name']) ?>" required>
								</label>

								<label class="row">
									<span class="muted">LinkedIn (URL)</span>
									<input type="url" name="linkedin" value="<?= h($candidate['linkedin'] ?? '') ?>" placeholder="https://linkedin.com/in/...">
								</label>

								<label class="row">
									<span class="muted">Manifest (optional)</span>
									<textarea name="statement" rows="5" placeholder="Add a short statement or manifesto"><?= h($candidate['statement'] ?? '') ?></textarea>
								</label>

								<label class="row">
									<span class="muted">Enable manifesto display</span>
									<input type="checkbox" name="manifesto_enabled" value="1"
									       <?= ($candidate['manifesto_enabled'] ?? true) ? 'checked' : '' ?>>
									<small class="muted">Uncheck to hide the "Read manifest" button for this candidate.</small>
								</label>

								<div class="candidate-form-actions">
									<button type="submit">Save</button>
									<button type="button" class="secondary candidate-cancel-btn" data-edit-index="<?= $index ?>">Cancel</button>
								</div>
							</form>
						</td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else: ?>
		<p class="muted">No candidates registered.</p>
	<?php endif; ?>

	<?php if (!$locked): ?>
		<h3>Add a Candidate</h3>
		<form method="post" class="row">
			<input type="hidden" name="action" value="add_candidate">
			<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

			<label class="row">
				<span class="muted">Candidate name</span>
				<input type="text" name="name" required placeholder="John Doe">
			</label>

			<label class="row">
				<span class="muted">LinkedIn (optional)</span>
				<input type="url" name="linkedin" placeholder="https://linkedin.com/in/...">
			</label>

			<label class="row">
				<span class="muted">Manifest (optional)</span>
				<textarea name="statement" rows="5" placeholder="Add a short statement or manifesto"></textarea>
			</label>

			<label class="row">
				<span class="muted">Enable manifesto display</span>
				<input type="checkbox" name="manifesto_enabled" value="1" checked>
				<small class="muted">Uncheck to hide the "Read manifest" button for this candidate only.</small>
			</label>

			<button type="submit">Add candidate</button>
		</form>
	<?php endif; ?>

	<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
	<script nonce="<?= h(csp_nonce()) ?>">
	function toggleEdit(index) {
		const row = document.getElementById('row-' + index);
		const form = document.getElementById('edit-' + index);

		// Check if form is currently shown (inline style === 'table-row')
		if (form.style.display === 'table-row') {
			// Currently shown, so hide it
			form.style.display = 'none';
		} else {
			// Currently hidden, so show it
			// Hide all other edit forms first
			document.querySelectorAll('.edit-form').forEach(f => f.style.display = 'none');
			// Show this edit form
			form.style.display = 'table-row';
			// Initialize Quill for this edit form if not already initialized
			initQuillForForm(index);
		}
	}

	// Store Quill instances
	const quillInstances = {};

	function initQuillForForm(index) {
		const textareaId = 'statement-edit-' + index;
		const textarea = document.querySelector('#edit-' + index + ' textarea[name="statement"]');

		if (!textarea || quillInstances[textareaId]) return;

		// Create container wrapper for Quill to prevent gap issues
		const container = document.createElement('div');
		container.className = 'quill-wrapper';

		// Create wrapper for Quill
		const wrapper = document.createElement('div');
		wrapper.id = textareaId;
		wrapper.innerHTML = textarea.value;

		container.appendChild(wrapper);
		textarea.style.display = 'none';
		textarea.parentNode.insertBefore(container, textarea);

		// Initialize Quill
		const quill = new Quill('#' + textareaId, {
			theme: 'snow',
			modules: {
				toolbar: [
					['bold', 'italic', 'underline'],
					[{ 'list': 'ordered'}, { 'list': 'bullet' }],
					['link'],
					['clean']
				]
			},
			placeholder: 'Add a short statement or manifesto'
		});

		// Store instance
		quillInstances[textareaId] = quill;

		// Update textarea on change
		quill.on('text-change', function() {
			textarea.value = quill.root.innerHTML;
		});
	}

	// Initialize Quill for "Add Candidate" form on page load
	document.addEventListener('DOMContentLoaded', function() {
		// Attach event listeners to Edit buttons
		document.querySelectorAll('.candidate-edit-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				const index = this.getAttribute('data-edit-index');
				if (index !== null) {
					toggleEdit(parseInt(index));
				}
			});
		});

		// Attach event listeners to Cancel buttons
		document.querySelectorAll('.candidate-cancel-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				const index = this.getAttribute('data-edit-index');
				if (index !== null) {
					toggleEdit(parseInt(index));
				}
			});
		});

		// Attach event listeners to Delete buttons (for confirmation)
		document.querySelectorAll('button[data-confirm]').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				const message = this.getAttribute('data-confirm');
				if (message && !confirm(message)) {
					e.preventDefault();
					return false;
				}
			});
		});

		const addFormTextarea = document.querySelector('form[method="post"]:not(.candidate-edit-form) textarea[name="statement"]');
		if (addFormTextarea) {
			// Create container wrapper for Quill to prevent gap issues
			const container = document.createElement('div');
			container.className = 'quill-wrapper';

			const wrapper = document.createElement('div');
			wrapper.id = 'statement-add';

			container.appendChild(wrapper);
			addFormTextarea.style.display = 'none';
			addFormTextarea.parentNode.insertBefore(container, addFormTextarea);

			const quill = new Quill('#statement-add', {
				theme: 'snow',
				modules: {
					toolbar: [
						['bold', 'italic', 'underline'],
						[{ 'list': 'ordered'}, { 'list': 'bullet' }],
						['link'],
						['clean']
					]
				},
				placeholder: 'Add a short statement or manifesto'
			});

			quill.on('text-change', function() {
				addFormTextarea.value = quill.root.innerHTML;
			});
		}
	});
	</script>

<?php endif;

if ($is_admin) {
	// Footer links removed for consistency with admin page navigation
	$footer_links = [];
}
$is_admin_page = true; // Tell footer we don't have flow-stack

include __DIR__ . '/../../footer.php';

?>
