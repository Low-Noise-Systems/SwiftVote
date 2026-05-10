<?php
/* ===================== HELPERS ===================== */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function load_json_file(string $path): array
{
    if (!file_exists($path))
        return [];
    $fp = fopen($path, 'r');
    if (!$fp)
        return [];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp) ?: '';
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function save_json_file(string $path, array $data): bool
{
    $tmp = $path . '.tmp';
    $fp = fopen($tmp, 'c+');
    if (!$fp)
        return false;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        if (file_exists($tmp))
            unlink($tmp);
        return false;
    }
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!rename($tmp, $path)) {
        if (file_exists($tmp))
            unlink($tmp);
        return false;
    }
    return true;
}
function append_csv_row(string $path, array $row): bool
{
    $is_new = !file_exists($path);
    $fp = fopen($path, 'a');
    if (!$fp)
        return false;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    if ($is_new) {
        fputcsv($fp, ['timestamp', 'email', 'selections', 'ip', 'ua']);
    }
    fputcsv($fp, $row);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
function csv_safe(string $v): string
{
    return preg_match('/^[=\-+@]/', $v) ? "'" . $v : $v;
}

/**
 * Detects the best available sealed box provider in the current PHP environment.
 *
 * @return array{ok:bool, provider?:string, callable?:callable, error?:string}
 */
function sealed_box_support_info(): array
{
    static $info = null;
    if ($info !== null)
        return $info;

    if (function_exists('sodium_crypto_box_seal')) {
        return $info = ['ok' => true, 'provider' => 'ext-sodium (global)', 'callable' => 'sodium_crypto_box_seal'];
    }
    if (function_exists('\\Sodium\\crypto_box_seal')) {
        return $info = ['ok' => true, 'provider' => 'ext-sodium (namespaced)', 'callable' => '\\Sodium\\crypto_box_seal'];
    }
    if (class_exists('Sodium') && method_exists('Sodium', 'crypto_box_seal')) {
        return $info = ['ok' => true, 'provider' => 'ext-sodium (class)', 'callable' => ['Sodium', 'crypto_box_seal']];
    }
    if (class_exists('ParagonIE_Sodium_Compat') && method_exists('ParagonIE_Sodium_Compat', 'crypto_box_seal')) {
        return $info = ['ok' => true, 'provider' => 'paragonie/sodium_compat', 'callable' => ['ParagonIE_Sodium_Compat', 'crypto_box_seal']];
    }

    return $info = ['ok' => false, 'error' => 'Aucun fournisseur crypto_box_seal disponible'];
}

/**
 * Performs sealed box encryption using the best available provider.
 *
 * @return string|null Raw ciphertext on success, null on failure.
 */
function sealed_box_encrypt(string $plaintext, string $recipientPublicKey): ?string
{
    $info = sealed_box_support_info();
    if (!$info['ok'])
        return null;
    $callable = $info['callable'] ?? null;
    if (!$callable)
        return null;
    return call_user_func($callable, $plaintext, $recipientPublicKey);
}

function load_voter_emails(string $path): array
{
    if (!file_exists($path))
        return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $set = [];
    foreach ($lines as $line) {
        $email = strtolower(trim($line));
        if (filter_var($email, FILTER_VALIDATE_EMAIL))
            $set[$email] = true;
    }
    return $set;
}

function is_admin_email(string $email, string $admin_emails_path): bool
{
    if (!file_exists($admin_emails_path))
        return false;
    $lines = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $email_lower = strtolower(trim($email));
    foreach ($lines as $line) {
        if (strtolower(trim($line)) === $email_lower)
            return true;
    }
    return false;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function require_csrf(string $token): void
{
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(400);
        exit('Bad CSRF token');
    }
}

function generate_token(string $email, string $secret): string
{
    $rnd = bin2hex(random_bytes(16));
    $mac = hash_hmac('sha256', $email . '|' . $rnd . '|' . microtime(true), $secret);
    return $rnd . '.' . $mac;
}
function now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function prune_tokens(array $tokens, int $now): array
{
    foreach ($tokens as $t => $e) {
        if (!is_array($e) || ($e['used'] ?? false) || $now > ($e['expires'] ?? 0))
            unset($tokens[$t]);
    }
    return $tokens;
}

/* ===================== FILE-PER-VOTER HELPERS ===================== */

/**
 * Generate vote filename from email using HMAC-SHA256 with secret key
 * SECURITY: Uses HMAC to prevent vote existence oracle attacks
 * Filename cannot be predicted without knowing the secret key
 *
 * @param string $email Voter's email address
 * @param string $secret_key Secret key for HMAC
 * @return string Filename (hmac.vote.enc)
 */
function get_vote_hash(string $email, string $secret_key): string
{
    return hash_hmac('sha256', strtolower(trim($email)), $secret_key);
}

function get_vote_filename(string $email, string $secret_key): string
{
    return get_vote_hash($email, $secret_key) . '.vote.enc';
}

/**
 * Get full path to vote file
 * @param string $email Voter's email address
 * @param string $votes_dir Path to votes directory
 * @param string $secret_key Secret key for HMAC
 * @return string Full path to vote file
 */
function get_vote_path(string $email, string $votes_dir, string $secret_key): string
{
    return $votes_dir . '/' . get_vote_filename($email, $secret_key);
}

/**
 * Check if someone has already voted (ultra-fast, no file reading required)
 * @param string $email Voter's email address
 * @param string $votes_dir Path to votes directory
 * @param string $secret_key Secret key for HMAC
 * @return bool True if already voted, false otherwise
 */
function has_voted(string $email, string $votes_dir, string $secret_key): bool
{
    return file_exists(get_vote_path($email, $votes_dir, $secret_key));
}

/**
 * Load candidates list from JSON file
 * @param string $candidates_json Path to candidates.json file
 * @return array Array of candidate objects with id, name, linkedin, statement
 */
function load_candidates(string $candidates_json): array
{
    if (!file_exists($candidates_json)) {
        return [];
    }
    $json = file_get_contents($candidates_json);
    if ($json === false) {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) && isset($data['candidates']) ? $data['candidates'] : [];
}

/**
 * Resolve the URL for a candidate image (any supported extension), falling back to placeholder
 * @param array $candidate Candidate data (expects id and optional image)
 * @param array $config App configuration containing candidate_images_dir and candidate_images_web_path
 * @return string Public URL to the candidate image or placeholder
 */
function candidate_image_url(array $candidate, array $config): string
{
    $images_dir = rtrim($config['candidate_images_dir'] ?? '', '/');
    $web_base = rtrim($config['candidate_images_web_path'] ?? '/assets/images/candidates', '/');
    $placeholder = $web_base . '/placeholder.png';

    if ($images_dir === '' || !is_dir($images_dir)) {
        return $placeholder;
    }

    $allowed_exts = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'PNG', 'JPG', 'JPEG', 'WEBP', 'GIF'];
    $base_names = [];

    $custom_image = $candidate['image'] ?? '';
    if (is_string($custom_image)) {
        $custom_image = trim($custom_image);
        if ($custom_image !== '') {
            $base_names[] = basename($custom_image);
        }
    }

    $candidate_id = $candidate['id'] ?? '';
    if (is_string($candidate_id)) {
        $sanitized_id = preg_replace('/[^a-z0-9_\-]/i', '', $candidate_id);
        if ($sanitized_id !== '') {
            $base_names[] = $sanitized_id;
        }
    }

    foreach ($base_names as $base) {
        if ($base === '')
            continue;

        $direct_path = $images_dir . '/' . $base;
        if (is_file($direct_path)) {
            return $web_base . '/' . $base;
        }

        $name_without_ext = $base;
        if (pathinfo($base, PATHINFO_EXTENSION) !== '') {
            $name_without_ext = pathinfo($base, PATHINFO_FILENAME);
        }

        foreach ($allowed_exts as $ext) {
            $filename = $name_without_ext . '.' . $ext;
            if (is_file($images_dir . '/' . $filename)) {
                return $web_base . '/' . $filename;
            }
        }
    }

    return $placeholder;
}

/**
 * Validate that submitted candidate IDs are valid
 * @param array $submitted_ids Array of candidate IDs submitted by voter
 * @param string $candidates_json Path to candidates.json file
 * @return bool True if all IDs are valid, false otherwise
 */
function validate_candidate_ids(array $submitted_ids, string $candidates_json): bool
{
    $candidates = load_candidates($candidates_json);
    $valid_ids = array_column($candidates, 'id');

    foreach ($submitted_ids as $id) {
        if (!in_array($id, $valid_ids, true)) {
            return false;
        }
    }
    return true;
}

/* ===================== VOTING STATE & SCHEDULE MANAGEMENT ===================== */

/**
 * Load voting state from JSON file
 * @param string $state_file Path to voting_state.json file
 * @return array Voting state data
 */
function load_voting_state(string $state_file): array
{
    $default = [
        'period' => ['start' => null, 'end' => null],
        'manual_override' => false, // Default to false in manual mode
        'mode' => 'manual' // 'scheduled' or 'manual' - default is manual
    ];

    if (!file_exists($state_file)) {
        return $default;
    }

    $data = load_json_file($state_file);
    return array_merge($default, $data);
}

/**
 * Save voting state to JSON file
 * @param string $state_file Path to voting_state.json file
 * @param array $state Voting state data
 * @return bool True on success, false on failure
 */
function save_voting_state(string $state_file, array $state): bool
{
    return save_json_file($state_file, $state);
}

/**
 * Check if voting is currently open based on state array
 * @param array $state Voting state array
 * @return bool True if voting is open, false otherwise
 */
function state_is_open(array $state): bool
{
    $mode = $state['mode'] ?? 'manual';

    if ($mode === 'manual') {
        return ($state['manual_override'] ?? false) === true;
    }

    $period = $state['period'] ?? ['start' => null, 'end' => null];
    if (empty($period['start']))
        return false;

    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = new DateTimeImmutable($period['start'], new DateTimeZone('UTC'));

        if (empty($period['end'])) {
            return $now >= $start;
        }

        $end = new DateTimeImmutable($period['end'], new DateTimeZone('UTC'));
        return ($now >= $start && $now <= $end);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if voting is currently open
 * Checks manual override first, then time period
 * @param string $state_file Path to voting_state.json file
 * @return bool True if voting is open, false otherwise
 */
function is_voting_open(string $state_file): bool
{
    $state = load_voting_state($state_file);
    return state_is_open($state);
}

/**
 * Count total number of votes cast
 * @param string $votes_dir Path to votes directory
 * @return int Number of votes
 */
function count_votes(string $votes_dir): int
{
    if (!is_dir($votes_dir))
        return 0;

    $files = glob($votes_dir . '/*.vote.enc');
    return $files === false ? 0 : count($files);
}

/**
 * Check if candidates are locked (any votes exist)
 * @param string $votes_dir Path to votes directory
 * @return bool True if candidates are locked, false otherwise
 */
function are_candidates_locked(string $votes_dir): bool
{
    return count_votes($votes_dir) > 0;
}

/**
 * Set voting period
 * @param string $state_file Path to voting_state.json file
 * @param string $start ISO8601 start datetime
 * @param string $end ISO8601 end datetime
 * @return bool True on success, false on failure
 */
function set_voting_period(string $state_file, string $start, string $end): bool
{
    $state = load_voting_state($state_file);

    // Validate dates
    try {
        $start_dt = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        $end_dt = new DateTimeImmutable($end, new DateTimeZone('UTC'));

        if ($end_dt <= $start_dt) {
            return false; // End must be after start
        }

        $state['period'] = [
            'start' => $start_dt->format('Y-m-d\TH:i:s\Z'),
            'end' => $end_dt->format('Y-m-d\TH:i:s\Z')
        ];

        return save_voting_state($state_file, $state);
    } catch (Exception $e) {
        return false; // Invalid date format
    }
}

/**
 * Clear voting period
 * @param string $state_file Path to voting_state.json file
 * @return bool True on success, false on failure
 */
function clear_voting_period(string $state_file): bool
{
    $state = load_voting_state($state_file);

    $state['period'] = [
        'start' => null,
        'end' => null
    ];

    return save_voting_state($state_file, $state);
}

/**
 * Set manual override for voting (force open or closed)
 * @param string $state_file Path to voting_state.json file
 * @param bool $open True to force open, false to force closed
 * @return bool True on success, false on failure
 */
function set_manual_override(string $state_file, bool $open): bool
{
    $state = load_voting_state($state_file);
    $state['manual_override'] = $open;
    return save_voting_state($state_file, $state);
}

/**
 * Clear manual override (return to automatic time-based control)
 * @param string $state_file Path to voting_state.json file
 * @return bool True on success, false on failure
 */
function clear_manual_override(string $state_file): bool
{
    $state = load_voting_state($state_file);
    $state['manual_override'] = null;
    return save_voting_state($state_file, $state);
}

/**
 * Get comprehensive voting status
 * @param string $state_file Path to voting_state.json file
 * @param string $votes_dir Path to votes directory
 * @return array Voting status information
 */
function get_voting_status(string $state_file, string $votes_dir): array
{
    $state = load_voting_state($state_file);
    $vote_count = count_votes($votes_dir);
    $is_open = is_voting_open($state_file);
    $locked = are_candidates_locked($votes_dir);

    $period = $state['period'];
    $period_configured = !empty($period['start']) && !empty($period['end']);
    $is_active = false;
    $is_future = false;

    if ($period_configured) {
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $start = new DateTimeImmutable($period['start'], new DateTimeZone('UTC'));
            $end = new DateTimeImmutable($period['end'], new DateTimeZone('UTC'));

            $is_active = ($now >= $start && $now <= $end);
            $is_future = ($now < $start);
        } catch (Exception $e) {
            // Invalid dates, treat as not configured
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
        'manual_override' => $state['manual_override']
    ];
}

/* ===================== ANONYMOUS VOTER EXPORT HELPERS ===================== */

/**
 * Generate a random 8-character hex voter ID
 * @return string 8-character hexadecimal ID (e.g., "a1b2c3d4")
 */
function generate_voter_id(): string
{
    return bin2hex(random_bytes(4)); // 4 bytes = 8 hex chars
}

/**
 * Get path to mapping pubkey file (separate key for voter identity mapping)
 * @param array $config Application configuration
 * @return string Full path to mapping_pubkey.txt
 */
function get_mapping_pubkey_path(array $config): string
{
    return $config['data_dir'] . '/mapping_pubkey.txt';
}

/**
 * Get the mapping pubkey (base64 encoded)
 * @param array $config Application configuration
 * @return string|null Base64 encoded pubkey or null if not set
 */
function get_mapping_pubkey(array $config): ?string
{
    $path = get_mapping_pubkey_path($config);
    if (!file_exists($path)) {
        return null;
    }
    $content = file_get_contents($path);
    return $content !== false ? trim($content) : null;
}

/**
 * Check if mapping pubkey exists
 * @param array $config Application configuration
 * @return bool True if mapping pubkey file exists and is valid
 */
function has_mapping_pubkey(array $config): bool
{
    $pubkey = get_mapping_pubkey($config);
    if ($pubkey === null) {
        return false;
    }
    $bin = base64_decode($pubkey, true);
    return $bin !== false && strlen($bin) === 32;
}

/**
 * Get path to encrypted voter mapping file
 * @param array $config Application configuration
 * @return string Full path to voter_mapping.enc
 */
function get_voter_mapping_path(array $config): string
{
    return $config['data_dir'] . '/voter_mapping.enc';
}

/**
 * Save voter mapping entry (encrypted and appended to file)
 * Each entry is encrypted separately and stored on its own line
 *
 * @param string $voter_id The anonymous voter ID
 * @param string $email The voter's email address
 * @param string $pubkey_bin Binary public key for encryption
 * @param array $config Application configuration
 * @return bool True on success, false on failure
 */
function save_voter_mapping(string $voter_id, string $email, string $pubkey_bin, array $config): bool
{
    $mapping = json_encode(['voter_id' => $voter_id, 'email' => $email], JSON_UNESCAPED_UNICODE);
    $encrypted = sealed_box_encrypt($mapping, $pubkey_bin);
    if (!$encrypted) {
        return false;
    }

    $mapping_file = get_voter_mapping_path($config);
    $entry = base64_encode($encrypted) . "\n";
    return file_put_contents($mapping_file, $entry, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * Check if voter mapping file exists and has content
 * @param array $config Application configuration
 * @return bool True if mapping file exists and is not empty
 */
function has_voter_mapping(array $config): bool
{
    $mapping_file = get_voter_mapping_path($config);
    return file_exists($mapping_file) && filesize($mapping_file) > 0;
}
?>