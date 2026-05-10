<?php
/** @var array $config */
if (!isset($config) || !is_array($config)) {
    http_response_code(500);
    exit('Configuration not loaded.');
}
$action = $action ?? ($_GET['action'] ?? $_POST['action'] ?? null);
/**
 * Admin Verification Action
 * Handles token verification with two-step confirmation for admin login
 * 
 * GET: Validates token and shows confirmation prompt (doesn't consume token)
 * POST: Consumes token and completes admin authentication
 */

if ($action === 'verify') {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $token = $_GET['token'] ?? '';

    // POST: final confirmation, actually consume the token
    if ($method === 'POST') {
        $token = $_POST['token'] ?? $token;
        require_csrf($_POST['csrf'] ?? '');

        $pending = $_SESSION['pending_admin_token'] ?? null;
        if (!$token || !$pending || !hash_equals($pending, $token)) {
            unset($_SESSION['pending_admin_token']);
            $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
            header('Location: login.php'); exit;
        }

        // SECURITY: Use file locking to prevent race conditions (token reuse)
        $tokens_file = $config['tokens_json'];
        $fp = fopen($tokens_file, 'c+');
        if (!$fp) {
            $_SESSION['flash'] = ['err', 'System error. Try again.'];
            header('Location: login.php'); exit;
        }

        // Acquire exclusive lock for atomic check-and-set
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            $_SESSION['flash'] = ['err', 'System error. Try again.'];
            header('Location: login.php'); exit;
        }

        // Read and parse tokens under lock
        $content = stream_get_contents($fp);
        $tokens = json_decode($content ?: '{}', true) ?: [];
        $entry = $tokens[$token] ?? null;
        $now = time();

        if (!$entry || ($entry['used'] ?? false) || $now > ($entry['expires'] ?? 0)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            unset($_SESSION['pending_admin_token']);
            $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
            header('Location: login.php'); exit;
        }

        $email = $entry['email'];

        // Mark token used + prune, then write back atomically
        $entry['used'] = true; $tokens[$token] = $entry;
        $tokens = prune_tokens($tokens, $now);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        unset($_SESSION['pending_admin_token']);
        session_regenerate_id(true);
        // Clear any voter session when logging in as admin (role separation)
        unset($_SESSION['voter_email']);
        // SECURITY: Regenerate CSRF token after authentication to prevent token fixation
        unset($_SESSION['csrf']);
        // Set admin session (separate from voter session for security)
        $_SESSION['admin_email'] = $email;
        $_SESSION['last_activity'] = time();
        $_SESSION['flash'] = ['ok', 'Address verified. Admin access granted.'];
        header('Location: index.php'); exit;
    }

    // GET: validate token but require explicit confirmation to consume it
    if (!$token) {
        unset($_SESSION['pending_admin_token']);
        $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
        header('Location: login.php'); exit;
    }

    $tokens_file = $config['tokens_json'];
    $fp = fopen($tokens_file, 'c+');
    if (!$fp) {
        $_SESSION['flash'] = ['err', 'System error. Try again.'];
        header('Location: login.php'); exit;
    }

    // Shared lock is enough for read-only validation
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        $_SESSION['flash'] = ['err', 'System error. Try again.'];
        header('Location: login.php'); exit;
    }

    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $tokens = json_decode($content ?: '{}', true) ?: [];
    $entry = $tokens[$token] ?? null;
    $now = time();

    if (!$entry || ($entry['used'] ?? false) || $now > ($entry['expires'] ?? 0)) {
        unset($_SESSION['pending_admin_token']);
        $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
        header('Location: login.php'); exit;
    }

    $_SESSION['pending_admin_token'] = $token;

    $verify_token = $token;
    $token_expires_in = max(0, ($entry['expires'] ?? 0) - $now);
    $page_title = 'Confirm admin access';

    header_html($page_title, $config['app_name'], $config, '../assets/css/style.css');
    flash_html(null);
    include __DIR__ . '/../views/admin_verify_prompt.php';
    include __DIR__ . '/../../public/footer.php';
    exit;
}
?>
