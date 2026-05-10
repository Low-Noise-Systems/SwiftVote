<?php
/** @var array $config */
if (!isset($config) || !is_array($config)) {
    http_response_code(500);
    exit('Configuration not loaded.');
}
$action = $action ?? ($_GET['action'] ?? $_POST['action'] ?? null);

if ($action === 'verify') {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $token = $_GET['token'] ?? '';

    // POST: final confirmation, actually consume the token
    if ($method === 'POST') {
        $token = $_POST['token'] ?? $token;
        require_csrf($_POST['csrf'] ?? '');

        $pending = $_SESSION['pending_token'] ?? null;
        if (!$token || !$pending || !hash_equals($pending, $token)) {
            unset($_SESSION['pending_token']);
            $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
            header('Location: ' . $config['base_url']); exit;
        }

        // SECURITY: Use file locking to prevent race conditions (token reuse)
        $tokens_file = $config['tokens_json'];
        $fp = fopen($tokens_file, 'c+');
        if (!$fp) {
            $_SESSION['flash'] = ['err', 'System error. Try again.'];
            header('Location: ' . $config['base_url']); exit;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            $_SESSION['flash'] = ['err', 'System error. Try again.'];
            header('Location: ' . $config['base_url']); exit;
        }

        $content = stream_get_contents($fp);
        $tokens = json_decode($content ?: '{}', true) ?: [];
        $entry = $tokens[$token] ?? null;
        $now = time();

        if (!$entry || ($entry['used'] ?? false) || $now > ($entry['expires'] ?? 0)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            unset($_SESSION['pending_token']);
            $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
            header('Location: ' . $config['base_url']); exit;
        }

        $email = $entry['email'];
        if (!$config['allow_multiple_votes']) {
            if (has_voted($email, $config['votes_dir'], $config['secret_key'])) {
                $entry['used'] = true; $tokens[$token] = $entry;
                $tokens = prune_tokens($tokens, $now);

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);

                unset($_SESSION['pending_token']);
                $_SESSION['flash'] = ['ok', 'Your address is already verified and a vote has been recorded.'];
                header('Location: ' . rtrim($config['base_url'], '/') . '/vote/'); exit;
            }
        }

        $entry['used'] = true; $tokens[$token] = $entry;
        $tokens = prune_tokens($tokens, $now);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        unset($_SESSION['pending_token']);
        session_regenerate_id(true);
        unset($_SESSION['admin_email']);
        unset($_SESSION['csrf']);
        $_SESSION['voter_email'] = $email;
        $_SESSION['last_activity'] = time();
        $_SESSION['flash'] = ['ok', 'You have successfully connected.'];
        header('Location: ' . rtrim($config['base_url'], '/') . '/announcement/'); exit;
    }

    // GET: validate token but require explicit confirmation to consume it
    if (!$token) {
        unset($_SESSION['pending_token']);
        $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
        header('Location: ' . $config['base_url']); exit;
    }

    $tokens_file = $config['tokens_json'];
    $fp = fopen($tokens_file, 'c+');
    if (!$fp) {
        $_SESSION['flash'] = ['err', 'System error. Try again.'];
        header('Location: ' . $config['base_url']); exit;
    }

    // Shared lock is enough for read-only validation
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        $_SESSION['flash'] = ['err', 'System error. Try again.'];
        header('Location: ' . $config['base_url']); exit;
    }

    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $tokens = json_decode($content ?: '{}', true) ?: [];
    $entry = $tokens[$token] ?? null;
    $now = time();

    if (!$entry || ($entry['used'] ?? false) || $now > ($entry['expires'] ?? 0)) {
        unset($_SESSION['pending_token']);
        $_SESSION['flash'] = ['err', 'Invalid or expired link.'];
        header('Location: ' . $config['base_url']); exit;
    }

    $_SESSION['pending_token'] = $token;

    $verify_token = $token;
    $token_expires_in = max(0, ($entry['expires'] ?? 0) - $now);
    $page_title = 'Confirm your access';

    header_html($page_title, $config['app_name'], $config);
    flash_html(null);
    include __DIR__ . '/../views/verify_prompt.php';
    footer_html();
    exit;
}
?>
