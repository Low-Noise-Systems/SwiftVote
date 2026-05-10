<?php
/* ===================== VIEWS ===================== */
function header_html(string $title, string $app, array $config = [], string $css_path = 'assets/css/style.css'): void {
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="' . h($css_path) . '?v=5">'; // cache-busting query optional
	if (!empty($config['turnstile_site_key'])) {
		// render=explicit prevents the SDK from auto-rendering, since we render manually in includes/turnstile.php
		echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>';
	}
    echo '</head><body class="flow-shell"><div class="main-wrapper"><div class="card"><div class="flow-stack">';
    echo '<div class="flow-brand"><h1>' . h($app) . '</h1></div>';
}

function footer_html(): void {
    echo '<div class="footer flow-step"></div>';
    echo '</div></div></body></html>';
}
function flash_html(?array $flash): void {
    if (!$flash) return;
    [$type,$msg] = $flash;
    $cls = $type === 'ok' ? 'ok' : 'err';

    // Allow HTML for messages containing our controlled nested warning (flash__warning class)
    // This is safe because these messages come from config, not user input
    $allow_html = str_contains($msg, 'flash__warning');
    $safe_msg = $allow_html ? $msg : h($msg);

    echo '<div class="flash ' . $cls . '" id="flash-message">' . $safe_msg . '</div>';
    // Auto-dismiss success messages after 30 seconds to give admins time to read the login notice
    if ($type === 'ok') {
        echo '<script' . csp_nonce_attr() . '>
            setTimeout(function() {
                var flash = document.getElementById("flash-message");
                if (flash) {
                    flash.style.transition = "opacity 0.5s ease-out";
                    flash.style.opacity = "0";
                    setTimeout(function() { flash.style.display = "none"; }, 500);
                }
            }, 30000);
        </script>';
    }
}
?>
