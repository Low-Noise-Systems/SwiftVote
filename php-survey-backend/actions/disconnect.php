<?php
if ($action === 'disconnect') {
    // CSRF protection
    require_csrf($_POST['csrf'] ?? '');

    // Determine where to redirect based on session type
    $was_admin = isset($_SESSION['admin_email']);

    // Clear both admin and voter sessions for security
    unset($_SESSION['admin_email']);
    unset($_SESSION['voter_email']);
    unset($_SESSION['last_activity']);
    unset($_SESSION['announcement_acknowledged']);
    $_SESSION['flash'] = ['ok', 'Logged out.'];

    // Redirect both admin and voter sessions to the app root
    $base_url = resolve_base_url($config);
    header('Location: ' . $base_url);
    exit;
}
?>
