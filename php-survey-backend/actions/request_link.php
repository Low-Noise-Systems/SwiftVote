<?php
/** @var array $config */
if (!isset($config) || !is_array($config)) {
    http_response_code(500);
    exit('Configuration not loaded.');
}
$action = $action ?? ($_GET['action'] ?? $_POST['action'] ?? null);

if ($action === 'request_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf'] ?? '');
    
    // SECURITY: Check if voting is open (admins can bypass this)
    $is_admin = isset($_SESSION['admin_email']);
    if (!is_voting_open($config['voting_state_json']) && !$is_admin) {
        $_SESSION['flash'] = ['err', 'Voting is currently closed. Please check back later.'];
        header('Location: ' . $config['base_url']); exit;
    }
    
    $email = strtolower(trim($_POST['email'] ?? ''));

    // Verify Turnstile (only if enabled)
    if (!empty($config['turnstile_enabled'])) {
        $turnstile_token = $_POST['cf-turnstile-response'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!verify_turnstile($turnstile_token, $config['turnstile_secret_key'], $ip)) {
            $_SESSION['flash'] = ['err', 'Security verification failed. Please try again.'];
            header('Location: ' . $config['base_url']); exit;
        }
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['err', 'Adresse e-mail invalide'];
        header('Location: ' . $config['base_url']); exit;
    }

    // New file-based rate limiting with IP reputation scoring
    require_once __DIR__ . '/../includes/rate_limiter.php';
    $limiter = new FileBasedRateLimiter();

    // Check if email is authorized (needed for rate limiter scoring)
    $allowed = load_voter_emails($config['voter_emails']);
    $is_valid_email = isset($allowed[$email]);

    // Check rate limit with IP reputation system (voter context)
    $rate_result = $limiter->check_access($ip, $email, $is_valid_email, 'voter');

    // Handle blocking (IP reputation too low)
    if ($rate_result['blocked']) {
        http_response_code(429);
        $_SESSION['flash'] = ['err', "Too many attempts. Try again in {$rate_result['minutes']} minutes."];
        header('Location: ' . $config['base_url']); exit;
    }

    // Handle cooldown or email not authorized (show neutral message)
    if (!$rate_result['send_email']) {
        $_SESSION['flash'] = ['ok', $config['request_ack_msg']];
        header('Location: ' . $config['base_url']); exit;
    }

    // At this point: email is valid, not in cooldown, and rate limit is OK
    if (!$is_valid_email) {
        // Should not reach here, but safety check
        $_SESSION['flash'] = ['ok', $config['request_ack_msg']];
        header('Location: ' . $config['base_url']); exit;
    }

    // SECURITY: Always perform same operations to prevent timing attacks
    // Check if user has already voted (but continue processing)
    $already_voted = false;
    if (!$config['allow_multiple_votes']) {
        $already_voted = has_voted($email, $config['votes_dir'], $config['secret_key']);
    }

    // Always create token and perform file operations (constant-time behavior)
    $token = generate_token($email, $config['secret_key']);
    $tokens = load_json_file($config['tokens_json']);
    $now = time();
    $tokens = prune_tokens($tokens, $now);

    if (!$already_voted) {
        // Store token only if user hasn't voted
        $tokens[$token] = ['email'=>$email, 'expires'=>$now + (int)$config['token_ttl_secs'], 'used'=>false];
    }
    save_json_file($config['tokens_json'], $tokens);

    if (!$already_voted) {
        // Send email only if user hasn't voted
        $link = $config['base_url'] . '?action=verify&token=' . urlencode($token);
        $sent = send_smtp_mail(
            $config,
            $email,
            mail_subject('verify', $config),
            render_verify_mail($link, $config)
        );
        if (!$sent) { error_log('MAIL: verify mail failed'); }
    }
    // Note: No else branch needed - delay happens below for ALL requests

    // SECURITY: Add random delay to ALL requests to prevent timing attacks
    // This masks timing differences between SMTP/hosting/already-voted paths
    usleep(random_int(500000, 3000000)); // 0.5-3 seconds

    // Always show the same neutral message to avoid information leaks
    $_SESSION['flash'] = ['ok', $config['request_ack_msg']];
    header('Location: ' . $config['base_url']); exit;
}
?>
