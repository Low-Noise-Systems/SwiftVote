<?php
// Usage: include_once 'includes/turnstile.php';
// Renders the Turnstile widget with correct site key for localhost or production

function render_turnstile_widget($config, $button_id = 'submit-btn') {
    // Skip if Turnstile is not enabled
    if (empty($config['turnstile_enabled'])) {
        return;
    }
    // Use Cloudflare test key for localhost
    $is_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8000'], true);
    $site_key = (string) ($is_localhost ? '1x00000000000000000000AA' : ($config['turnstile_site_key'] ?? ''));
    if (empty($site_key)) return;
    echo '<div class="cf-turnstile"></div>';
    echo '<div id="turnstile-fallback"></div>';
    echo '<script' . csp_nonce_attr() . '>
    document.addEventListener("DOMContentLoaded", function() {
        var sitekey = ' . json_encode($site_key) . ';
        var rendered = false;
        var fallbackTimeout = setTimeout(function() {
            if (!rendered) {
                var btn = document.getElementById(' . json_encode($button_id) . ');
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = "1";
                    btn.style.cursor = "pointer";
                }
                var fb = document.getElementById("turnstile-fallback");
                if (fb) {
                    fb.textContent = "⚠️ Unable to load Cloudflare Turnstile verification. You can continue, but submission may fail. Try refreshing the page if needed.";
                    fb.style.display = "block";
                }
            }
        }, 5000); // 5 seconds fallback
        function renderTurnstile() {
            var container = document.querySelector(".cf-turnstile");
            if (container && !container.hasAttribute("data-turnstile-rendered")) {
                window.turnstile.render(container, {
                    sitekey: sitekey,
                    callback: function(token) {
                        var btn = document.getElementById(' . json_encode($button_id) . ');
                        if (btn) {
                            btn.disabled = false;
                            btn.style.opacity = "1";
                            btn.style.cursor = "pointer";
                        }
                        var fb = document.getElementById("turnstile-fallback");
                        if (fb) fb.style.display = "none";
                    }
                });
                container.setAttribute("data-turnstile-rendered", "1");
                rendered = true;
                clearTimeout(fallbackTimeout);
            }
        }
        if (window.turnstile) {
            renderTurnstile();
        } else {
            var check = setInterval(function() {
                if (window.turnstile) {
                    clearInterval(check);
                    renderTurnstile();
                }
            }, 200);
        }
    });
    </script>';
}
