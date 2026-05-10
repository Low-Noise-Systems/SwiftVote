<?php
/**
 * Debug Helper Functions
 *
 * Provides development-only authentication shortcuts for testing
 * SECURITY: Only works when DEBUG_MODE=true AND on localhost
 */
declare(strict_types=1);

/**
 * Check if debug mode is enabled and request is from localhost
 * This function ensures debug features only work in development environment
 *
 * @return bool True if debug mode is active and on localhost
 */
function is_debug_enabled(): bool {
    // Check if DEBUG_MODE environment variable is set to 'true'
    if (getenv('DEBUG_MODE') !== 'true') {
        return false;
    }

    // Check if request is from localhost
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

    $is_localhost = (
        $host === 'localhost' ||
        str_starts_with($host, 'localhost:') ||
        $host === '127.0.0.1' ||
        str_starts_with($host, '127.0.0.1:') ||
        $remote_addr === '127.0.0.1' ||
        $remote_addr === '::1'
    );

    return $is_localhost;
}

/**
 * Quick login as admin (debug only)
 * Bypasses email verification and directly sets admin session
 *
 * SECURITY: Only works when is_debug_enabled() returns true
 *
 * @param string $email Admin email to login as
 * @param array $config Application configuration
 * @return bool True if login successful, false otherwise
 */
function debug_login_as_admin(string $email, array $config): bool {
    if (!is_debug_enabled()) {
        error_log('Debug login blocked: DEBUG_MODE is disabled or not on localhost');
        return false;
    }

    // Verify the email exists in admin_emails.txt
    $admin_emails_path = dirname(__DIR__) . '/config/admin_emails.txt';

    if (!file_exists($admin_emails_path)) {
        error_log('Debug login failed: admin_emails.txt not found');
        return false;
    }

    $admin_emails = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($admin_emails === false) {
        error_log('Debug login failed: could not read admin_emails.txt');
        return false;
    }

    $is_valid_admin = false;
    foreach ($admin_emails as $line) {
        if (strcasecmp(trim($line), $email) === 0) {
            $is_valid_admin = true;
            break;
        }
    }

    if (!$is_valid_admin) {
        error_log('Debug login failed: email not in admin list: ' . $email);
        return false;
    }

    // Clear any existing session and create new admin session
    session_regenerate_id(true);
    unset($_SESSION['voter_email']);
    unset($_SESSION['csrf']);
    $_SESSION['admin_email'] = $email;
    $_SESSION['last_activity'] = time();

    error_log('Debug login successful: logged in as admin ' . $email);
    return true;
}

/**
 * Quick login as voter (debug only)
 * Bypasses email verification and directly sets voter session
 *
 * SECURITY: Only works when is_debug_enabled() returns true
 *
 * @param string $email Voter email to login as
 * @param array $config Application configuration
 * @return bool True if login successful, false otherwise
 */
function debug_login_as_voter(string $email, array $config): bool {
    if (!is_debug_enabled()) {
        error_log('Debug login blocked: DEBUG_MODE is disabled or not on localhost');
        return false;
    }

    // Load voter emails
    if (!function_exists('load_voter_emails')) {
        require_once __DIR__ . '/helpers.php';
    }

    $voter_emails = load_voter_emails($config['voter_emails']);

    if (!isset($voter_emails[$email])) {
        error_log('Debug login failed: email not in voter list: ' . $email);
        return false;
    }

    // Clear any existing session and create new voter session
    session_regenerate_id(true);
    unset($_SESSION['admin_email']);
    unset($_SESSION['csrf']);
    $_SESSION['voter_email'] = $email;
    $_SESSION['last_activity'] = time();

    error_log('Debug login successful: logged in as voter ' . $email);
    return true;
}

/**
 * Get first admin email from config (for quick debug login)
 *
 * @return string|null First admin email or null if none found
 */
function get_first_admin_email(): ?string {
    $admin_emails_path = dirname(__DIR__) . '/config/admin_emails.txt';

    if (!file_exists($admin_emails_path)) {
        return null;
    }

    $admin_emails = file($admin_emails_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($admin_emails === false || empty($admin_emails)) {
        return null;
    }

    // Return first non-empty line
    foreach ($admin_emails as $line) {
        $email = trim($line);
        if ($email !== '' && !str_starts_with($email, '#')) {
            return $email;
        }
    }

    return null;
}

/**
 * Get first voter email from config (for quick debug login)
 *
 * @param array $config Application configuration
 * @return string|null First voter email or null if none found
 */
function get_first_voter_email(array $config): ?string {
    if (!function_exists('load_voter_emails')) {
        require_once __DIR__ . '/helpers.php';
    }

    $voter_emails = load_voter_emails($config['voter_emails']);

    // In debug mode prefer a known voter account for faster local testing
    $preferred_debug_voter = 'voter1@example.com';
    if (isset($voter_emails[$preferred_debug_voter])) {
        return $preferred_debug_voter;
    }

    if (empty($voter_emails)) {
        return null;
    }

    // Get first email from the array
    reset($voter_emails);
    return key($voter_emails);
}

/**
 * Handle debug login action
 * Processes POST requests for debug login
 *
 * @param array $config Application configuration
 * @return void Redirects after login or shows error
 */
function handle_debug_login_action(array $config): void {
    if (!is_debug_enabled()) {
        http_response_code(403);
        die('Debug mode is not enabled or not on localhost');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('Method not allowed');
    }

    $role = $_POST['debug_role'] ?? '';
    $email = $_POST['debug_email'] ?? '';

    if ($role === 'admin') {
        if (!$email) {
            $email = get_first_admin_email();
        }

        if ($email && debug_login_as_admin($email, $config)) {
            $_SESSION['flash'] = ['ok', '🔧 Debug mode: Logged in as admin'];
            header('Location: /admin/');
            exit;
        } else {
            $_SESSION['flash'] = ['err', 'Debug login failed'];
            header('Location: ' . $config['base_url']);
            exit;
        }
    } elseif ($role === 'voter') {
        if (!$email) {
            $email = get_first_voter_email($config);
        }

        if ($email && debug_login_as_voter($email, $config)) {
            $_SESSION['flash'] = ['ok', '🔧 Debug mode: Logged in as voter'];
            header('Location: /announcement/');
            exit;
        } else {
            $_SESSION['flash'] = ['err', 'Debug login failed'];
            header('Location: ' . $config['base_url']);
            exit;
        }
    } else {
        $_SESSION['flash'] = ['err', 'Invalid debug action'];
        header('Location: ' . $config['base_url']);
        exit;
    }
}

/**
 * Render debug login panel HTML
 * Shows quick login buttons for admin and voter roles
 * Only displays when is_debug_enabled() returns true
 *
 * @param array $config Application configuration
 * @return void Outputs HTML directly
 */
function render_debug_panel(array $config): void {
    if (!is_debug_enabled()) {
        return;
    }

    $first_admin = get_first_admin_email();
    $first_voter = get_first_voter_email($config);

    ?>
    <div class="debug-panel" style="background: #fff3cd; border: 2px dashed #ffc107; border-radius: 8px; padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #856404;">🔧 Debug Mode Active</h3>
        <p style="color: #856404; margin: 10px 0;">Quick login without email verification (localhost only)</p>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
            <?php if ($first_admin): ?>
            <form method="POST" action="?action=debug_login" style="margin: 0;">
                <input type="hidden" name="debug_role" value="admin">
                <input type="hidden" name="debug_email" value="<?php echo htmlspecialchars($first_admin, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" style="background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">
                    🔑 Login as Admin
                </button>
                <div style="font-size: 11px; color: #856404; margin-top: 5px;">
                    <?php echo htmlspecialchars($first_admin, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($first_voter): ?>
            <form method="POST" action="?action=debug_login" style="margin: 0;">
                <input type="hidden" name="debug_role" value="voter">
                <input type="hidden" name="debug_email" value="<?php echo htmlspecialchars($first_voter, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">
                    🗳️ Login as Voter
                </button>
                <div style="font-size: 11px; color: #856404; margin-top: 5px;">
                    <?php echo htmlspecialchars($first_voter, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ffc107;">
            <details style="color: #856404;">
                <summary style="cursor: pointer; font-weight: bold;">Advanced: Login as specific user</summary>
                <div style="margin-top: 10px;">
                    <form method="POST" action="?action=debug_login" style="margin: 10px 0;">
                        <input type="hidden" name="debug_role" value="admin">
                        <input type="email" name="debug_email" placeholder="admin@example.com" required
                               style="padding: 8px; border: 1px solid #ffc107; border-radius: 4px; width: 250px;">
                        <button type="submit" style="background: #dc3545; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-left: 5px;">
                            Login as Admin
                        </button>
                    </form>

                    <form method="POST" action="?action=debug_login" style="margin: 10px 0;">
                        <input type="hidden" name="debug_role" value="voter">
                        <input type="email" name="debug_email" placeholder="voter@example.com" required
                               style="padding: 8px; border: 1px solid #ffc107; border-radius: 4px; width: 250px;">
                        <button type="submit" style="background: #28a745; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-left: 5px;">
                            Login as Voter
                        </button>
                    </form>
                </div>
            </details>
        </div>

        <div style="margin-top: 10px; font-size: 12px; color: #856404;">
            <strong>Note:</strong> This panel only appears when DEBUG_MODE=true in .env and accessing from localhost
        </div>
    </div>
    <?php
}
?>
