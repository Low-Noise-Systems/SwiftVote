<?php
$footer_links = $footer_links ?? [];
if (!is_array($footer_links)) {
	$footer_links = [];
}

$footer_admin_link = $footer_admin_link ?? null;
$footer_admin_label = $footer_admin_label ?? 'Admin Section';
if ($footer_admin_link) {
	$footer_links[] = [
		'href' => (string) $footer_admin_link,
		'label' => (string) $footer_admin_label,
	];
}

function is_safe_footer_href(string $href): bool {
	$href = ltrim($href);
	if ($href === '') {
		return false;
	}
	if (preg_match('/^(javascript|data):/i', $href)) {
		return false;
	}
	if (str_starts_with($href, '//')) {
		return false;
	}

	$scheme = parse_url($href, PHP_URL_SCHEME);
	if ($scheme === null) {
		return true; // Relative URLs and anchors are allowed
	}

	return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
}

$footer_links = array_values(array_filter(array_map(function ($link) {
	if (!is_array($link)) {
		return null;
	}
	$href = isset($link['href']) ? trim((string) $link['href']) : '';
	$label = isset($link['label']) ? trim((string) $link['label']) : '';
	if ($href === '' || $label === '') {
		return null;
	}
	if (!is_safe_footer_href($href)) {
		return null;
	}
	return ['href' => $href, 'label' => $label];
}, $footer_links)));

$is_admin_user = isset($is_admin) ? (bool) $is_admin : false;

// Check if user is logged in (voter or admin)
$voter_email = $_SESSION['voter_email'] ?? null;
$admin_email = $_SESSION['admin_email'] ?? null;
$is_logged_in = !empty($voter_email) || !empty($admin_email);

$has_links = $is_admin_user && !empty($footer_links);
$show_disconnect = $is_logged_in;
$footer_has_content = $has_links || $show_disconnect;
?>
<?php if ($footer_has_content): ?>
<div class="footer flow-step" style="margin-top: 30px;">
	<div class="footer-admin">
		<?php if ($has_links): ?>
			<?php foreach ($footer_links as $link): ?>
				<p class="admin-link"><a href="<?= h($link['href']) ?>"><?= h($link['label']) ?></a></p>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ($show_disconnect): ?>
			<form method="post" class="disconnect-form">
				<input type="hidden" name="action" value="disconnect">
				<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
				<button type="submit" class="disconnect-btn">Disconnect</button>
			</form>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>
<script nonce="<?= h(csp_nonce()) ?>">
(function () {
	function lockForm(form) {
		var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
		for (var i = 0; i < buttons.length; i++) {
			var btn = buttons[i];
			var busyText = btn.getAttribute('data-busy-text') || 'Sending...';
			if (btn.tagName === 'BUTTON') {
				btn.dataset.originalText = btn.textContent;
				btn.textContent = busyText;
			} else {
				btn.dataset.originalValue = btn.value;
				btn.value = busyText;
			}
			btn.disabled = true;
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var forms = document.querySelectorAll('form[data-prevent-multi-submit]');
		for (var i = 0; i < forms.length; i++) {
			(function (form) {
				form.addEventListener('submit', function (event) {
					if (form.dataset.submissionLocked === 'true') {
						event.preventDefault();
						return;
					}
					form.dataset.submissionLocked = 'true';
					lockForm(form);
				});
			})(forms[i]);
		}
	});
})();
</script>
<?php
// Determine if we're in a flow-shell layout (public pages with flow-stack)
// vs a regular layout (admin pages without flow-stack)
$is_flow_shell = isset($is_flow_shell) ? $is_flow_shell : !isset($is_admin_page);
?>
<?php if ($is_flow_shell): ?>
</div><!-- close flow-stack -->
<?php endif; ?>
</div><!-- close card -->
<!-- Made by section - outside card container -->
<div class="made-by-footer">
	<p>
		Made by <a href="https://lownoisesystems.org" target="_blank" rel="noopener noreferrer">lownoisesystems.org</a>
	</p>
</div>
</div><!-- close main-wrapper -->
</body></html>
