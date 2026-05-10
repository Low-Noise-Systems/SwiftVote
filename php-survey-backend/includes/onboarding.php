<?php
declare(strict_types=1);

/**
 * Onboarding helpers for admin interface
 * Provides checklist detection and dismissal tracking
 */

/**
 * Load onboarding dismissal data
 */
function load_onboarding_data(string $file): array {
	if (!file_exists($file)) {
		return [];
	}
	$content = file_get_contents($file);
	if ($content === false) {
		return [];
	}
	$data = json_decode($content, true);
	return is_array($data) ? $data : [];
}

/**
 * Save onboarding dismissal data
 */
function save_onboarding_data(string $file, array $data): bool {
	$dir = dirname($file);
	if (!is_dir($dir)) {
		if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
			return false;
		}
	}
	$tmp = $file . '.tmp';
	$fp = fopen($tmp, 'c+');
	if (!$fp) return false;
	if (!flock($fp, LOCK_EX)) {
		fclose($fp);
		if (file_exists($tmp)) unlink($tmp);
		return false;
	}
	ftruncate($fp, 0);
	fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	fflush($fp);
	flock($fp, LOCK_UN);
	fclose($fp);
	if (!rename($tmp, $file)) {
		if (file_exists($tmp)) unlink($tmp);
		return false;
	}
	return true;
}

/**
 * Check if encryption key is initialized
 */
function check_encryption_key(array $config): bool {
	// Public key is stored in php-survey-backend/data/pubkey.txt (not in vote_data)
	$backend_dir = dirname(dirname(__FILE__)); // php-survey-backend/
	$pubkey_file = $backend_dir . '/data/pubkey.txt';
	if (!file_exists($pubkey_file)) {
		return false;
	}
	$content = trim(file_get_contents($pubkey_file));
	// Verify it's a valid base64 encoded 32-byte key
	$decoded = base64_decode($content, true);
	return $decoded !== false && strlen($decoded) === 32;
}

/**
 * Check if candidates have been added
 */
function check_candidates_configured(array $config): int {
	$candidates_file = $config['candidates_json'];
	if (!file_exists($candidates_file)) {
		return 0;
	}
	$content = file_get_contents($candidates_file);
	if ($content === false) {
		return 0;
	}
	$data = json_decode($content, true);
	if (!is_array($data) || !isset($data['candidates'])) {
		return 0;
	}
	return count($data['candidates']);
}

/**
 * Check if vote parameters have been configured
 * We consider them configured if the user has explicitly saved settings
 * (indicated by the presence of 'last_configured_at' timestamp)
 */
function check_vote_params_configured(array $config): bool {
	$params_file = $config['vote_params_json'];
	if (!file_exists($params_file)) {
		return false;
	}

	// Verify the file contains valid JSON data
	$content = file_get_contents($params_file);
	if ($content === false) {
		return false;
	}
	$data = json_decode($content, true);
	if (!is_array($data)) {
		return false;
	}

	// Check if user has explicitly configured settings (timestamp present)
	return isset($data['last_configured_at']) && !empty($data['last_configured_at']);
}

/**
 * Check if voting has ever been opened
 */
function check_vote_ever_opened(array $config): bool {
	$state_file = $config['voting_state_json'];
	if (!file_exists($state_file)) {
		return false;
	}
	$content = file_get_contents($state_file);
	if ($content === false) {
		return false;
	}
	$data = json_decode($content, true);
	if (!is_array($data)) {
		return false;
	}
	return ($data['vote_ever_opened'] ?? false) === true;
}

/**
 * Check if announcement has been configured
 * We consider it configured if the content is not empty
 */
function check_announcement_configured(array $config): bool {
	$announcement_file = $config['announcement_json'];
	if (!file_exists($announcement_file)) {
		return false;
	}
	$content = file_get_contents($announcement_file);
	if ($content === false) {
		return false;
	}
	$data = json_decode($content, true);
	if (!is_array($data)) {
		return false;
	}
	// Check if content is not empty
	return !empty($data['content']) && trim($data['content']) !== '';
}

/**
 * Get onboarding checklist status
 * Returns array with:
 * - steps: array of step statuses
 * - completed_count: number of completed steps
 * - total_count: total number of steps
 * - all_complete: boolean
 */
function get_onboarding_checklist(array $config): array {
	$encryption_key = check_encryption_key($config);
	$candidates_count = check_candidates_configured($config);
	$params_configured = check_vote_params_configured($config);
	$announcement_configured = check_announcement_configured($config);
	$vote_opened = check_vote_ever_opened($config);

	$steps = [
		[
			'title' => 'Generate encryption key',
			'completed' => $encryption_key,
			'link' => 'vote-key/',
			'icon' => '🔑'
		],
		[
			'title' => 'Add candidates',
			'completed' => $candidates_count > 0,
			'link' => 'candidates/',
			'icon' => '👥',
			'detail' => $candidates_count > 0 ? "($candidates_count candidate" . ($candidates_count > 1 ? 's' : '') . ")" : ''
		],
		[
			'title' => 'Configure voting parameters',
			'completed' => $params_configured,
			'link' => 'vote-settings/',
			'icon' => '⚙️'
		],
	];

	// Add mapping key step if anonymous voter export is enabled
	if ($config['anonymous_voter_export'] ?? false) {
		$mapping_key_exists = has_mapping_pubkey($config);
		$steps[] = [
			'title' => 'Generate mapping encryption key',
			'completed' => $mapping_key_exists,
			'link' => 'vote-key/#mapping-key',
			'icon' => '🔐',
			'detail' => 'Separate key for voter identities'
		];
	}

	$steps[] = [
		'title' => 'Configure announcement to voters',
		'completed' => $announcement_configured,
		'link' => 'announcement-settings/',
		'icon' => '📢'
	];
	$steps[] = [
		'title' => 'Open the vote',
		'completed' => $vote_opened,
		'link' => '#voting',
		'icon' => '🗳️'
	];

	$completed_count = 0;
	foreach ($steps as $step) {
		if ($step['completed']) {
			$completed_count++;
		}
	}

	return [
		'steps' => $steps,
		'completed_count' => $completed_count,
		'total_count' => count($steps),
		'all_complete' => $completed_count === count($steps)
	];
}

/**
 * Check if onboarding banner should be shown for this admin
 * Returns true if should be shown, false otherwise
 */
function should_show_onboarding(array $config, string $admin_email): bool {
	$checklist = get_onboarding_checklist($config);

	// If all steps complete, never show
	if ($checklist['all_complete']) {
		return false;
	}

	// Check if admin has dismissed it
	$onboarding_file = $config['data_dir'] . '/admin_onboarding.json';
	$onboarding_data = load_onboarding_data($onboarding_file);

	if (isset($onboarding_data[$admin_email])) {
		$admin_data = $onboarding_data[$admin_email];
		$dismissed_until = $admin_data['dismissed_until'] ?? null;

		if ($dismissed_until) {
			try {
				$until_dt = new DateTimeImmutable($dismissed_until, new DateTimeZone('UTC'));
				$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

				// If still within dismissal period, don't show
				if ($now < $until_dt) {
					return false;
				}
			} catch (Exception $e) {
				// Invalid date, show banner
			}
		}
	}

	return true;
}

/**
 * Dismiss onboarding banner for an admin (for 3 days)
 */
function dismiss_onboarding(array $config, string $admin_email): bool {
	$onboarding_file = $config['data_dir'] . '/admin_onboarding.json';
	$onboarding_data = load_onboarding_data($onboarding_file);

	$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
	$until = $now->modify('+3 days');

	$onboarding_data[$admin_email] = [
		'dismissed_at' => $now->format('Y-m-d\TH:i:s\Z'),
		'dismissed_until' => $until->format('Y-m-d\TH:i:s\Z')
	];

	return save_onboarding_data($onboarding_file, $onboarding_data);
}

/**
 * Get complete onboarding status for display
 */
function get_onboarding_status(array $config, string $admin_email): array {
	$checklist = get_onboarding_checklist($config);
	$should_show = should_show_onboarding($config, $admin_email);

	return [
		'checklist' => $checklist,
		'should_show' => $should_show
	];
}
