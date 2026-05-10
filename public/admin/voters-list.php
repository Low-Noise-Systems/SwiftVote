<?php
declare(strict_types=1);

// Load admin helpers (includes security.php, helpers.php, and admin-specific functions)
require_once __DIR__ . '/../../php-survey-backend/includes/admin_helpers.php';

init_secure_session();

$log_dir = __DIR__ . '/../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

$config_path = __DIR__ . '/../../php-survey-backend/config/config.php';
if (!file_exists($config_path)) { http_response_code(500); exit('Critical Error: Configuration file not found.'); }
$config = require $config_path;

$trusted = $config['trusted_hosts'] ?? [];
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($trusted && !in_array($host, $trusted, true)) { http_response_code(400); exit('Unauthorized host'); }
$base_url = resolve_base_url($config);

require_configured_secret($config);

if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0750, true) && !is_dir($config['data_dir'])) {
	http_response_code(500); exit('Critical Error: Unable to create data directory.');
}

// Shared CSP for admin console (allows Turnstile + Quill/CDN assets)
send_default_csp($config);

if (!isset($_SESSION['admin_email'])) {
	$_SESSION['flash'] = ['err', 'Please log in to access this page.'];
	header('Location: login.php'); exit;
}

require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard_strict.php';

$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$action = $_GET['action'] ?? $_POST['action'] ?? 'view';

if ($action === 'disconnect') {
	require_csrf($_POST['csrf'] ?? '');
	unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
	$_SESSION['flash'] = ['ok', 'Disconnected.'];
	header('Location: ' . $base_url); exit;
}

$admin_emails_path = __DIR__ . '/../../php-survey-backend/config/admin_emails.txt';
$is_admin = isset($_SESSION['admin_email']) && is_admin_email((string) $_SESSION['admin_email'], $admin_emails_path);

// Enforce anonymity: block access to voter list while voting is open in anonymous mode
$voting_status = get_voting_status($config['voting_state_json'], $config['votes_dir']);
if ($config['anonymous_voter_export'] && $voting_status['is_open']) {
	$_SESSION['flash'] = ['err', 'Voter list is hidden while voting is open (anonymous mode enabled).'];
	header('Location: index.php'); exit;
}

$allowed_emails = [];
if (file_exists($config['voter_emails'])) {
	$allowed_emails = file($config['voter_emails'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$allowed_emails = array_unique($allowed_emails);
}

$voted_list = [];
$not_voted_count = 0;

foreach ($allowed_emails as $email) {
	$email = trim((string) $email);
	if ($email === '') continue;

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

usort($voted_list, function($a, $b) {
	return $b['timestamp'] <=> $a['timestamp'];
});

$total = count($allowed_emails);
$voted_count = count($voted_list);
$per_page = 25;
$total_pages = max(1, (int) ceil($voted_count / $per_page));
$current_page = min($total_pages, max(1, (int) ($_GET['page'] ?? 1)));
$offset = ($current_page - 1) * $per_page;
$paginated_voters = array_slice($voted_list, $offset, $per_page);
$showing_start = $voted_count > 0 ? $offset + 1 : 0;
$showing_end = $offset + count($paginated_voters);
$participation_rate = $total > 0 ? round($voted_count / $total * 100, 1) : 0;

admin_header_html('All Voters', $config['app_name'], $config);
admin_flash_html($flash);
?>
<h2 class="admin-main-title">All voters</h2>

<div class="admin-participation">
	<strong>Participation:</strong> <?= $voted_count ?> / <?= $total ?> (<?= $participation_rate ?>%)<br>
	<span class="muted">Haven't voted yet: <?= $not_voted_count ?></span>
</div>

<?php if (!empty($paginated_voters)): ?>
	<table class="admin-table">
		<thead>
			<tr>
				<th>Email</th>
				<th>Vote date</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($paginated_voters as $vote): ?>
				<tr>
					<td><?= h($vote['email']) ?></td>
					<td><?= h($vote['date']) ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<div class="admin-pagination">
		<span class="admin-pagination-info">
			Showing <?= h((string) $showing_start) ?> to <?= h((string) $showing_end) ?> of <?= h((string) $voted_count) ?>
		</span>
		<div class="admin-pagination-controls">
				<?php if ($current_page > 1): ?>
					<a class="admin-pagination-btn" href="voters-list.php?page=<?= h((string) ($current_page - 1)) ?>">Previous</a>
			<?php else: ?>
				<span class="admin-pagination-btn disabled">Previous</span>
			<?php endif; ?>
			<span class="admin-pagination-page">Page <?= h((string) $current_page) ?> of <?= h((string) $total_pages) ?></span>
				<?php if ($current_page < $total_pages): ?>
					<a class="admin-pagination-btn" href="voters-list.php?page=<?= h((string) ($current_page + 1)) ?>">Next</a>
			<?php else: ?>
				<span class="admin-pagination-btn disabled">Next</span>
			<?php endif; ?>
		</div>
	</div>
<?php else: ?>
	<p class="muted">No votes recorded yet.</p>
<?php endif; ?>

<div class="admin-table-actions">
	<a class="admin-see-more" href="index.php#voters">Back to admin dashboard</a>
</div>

<?php
$footer_links = [
	['href' => 'index.php#voters', 'label' => 'Back to Admin Panel']
];
$is_admin_page = true; // Tell footer we don't have flow-stack
include __DIR__ . '/../footer.php';
?>
