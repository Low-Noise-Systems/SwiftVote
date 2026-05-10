<?php
/**
 * Onboarding banner component
 * Displays a dismissible checklist for first-time admins
 *
 * Requires:
 * - $onboarding_status array from get_onboarding_status()
 * - $base_url for AJAX endpoint
 * - csrf_token() function
 */

// Force show in debug mode (can be dismissed with localStorage)
$force_show_debug = is_debug_enabled();

if (!isset($onboarding_status)) {
	return;
}

if (!$force_show_debug && !$onboarding_status['should_show']) {
	return;
}

$checklist = $onboarding_status['checklist'];
$steps = $checklist['steps'];
$completed = $checklist['completed_count'];
$total = $checklist['total_count'];
$progress_percent = ($total > 0) ? round(($completed / $total) * 100) : 0;
?>

<div id="onboarding-banner" class="onboarding-banner" data-admin-email="<?= h($_SESSION['admin_email'] ?? '') ?>">
	<div class="onboarding-header">
		<div class="onboarding-title">
			<span class="onboarding-icon">🚀</span>
			<h3>Initial Setup<?php if ($force_show_debug): ?> <span style="font-size: 0.7em; opacity: 0.6; font-weight: 400;">(debug mode)</span><?php endif; ?></h3>
			<span class="onboarding-progress-text"><?= $completed ?>/<?= $total ?> completed</span>
		</div>
		<button type="button" class="onboarding-dismiss" id="dismiss-onboarding" aria-label="Hide this guide">
			×
		</button>
	</div>

	<div class="onboarding-progress-bar">
		<div class="onboarding-progress-fill" style="width: <?= $progress_percent ?>%"></div>
	</div>

	<ul class="onboarding-checklist">
		<?php foreach ($steps as $index => $step): ?>
			<li class="onboarding-step <?= $step['completed'] ? 'completed' : 'pending' ?>">
				<span class="step-icon"><?= $step['completed'] ? '✅' : '⭕' ?></span>
				<span class="step-number"><?= $index + 1 ?>.</span>
				<span class="step-title">
					<?= h($step['title']) ?>
					<?php if (!empty($step['detail'])): ?>
						<span class="step-detail"><?= h($step['detail']) ?></span>
					<?php endif; ?>
				</span>
				<a href="<?= h($step['link']) ?>" class="step-action">Go to</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<div class="onboarding-footer">
		<button type="button" class="btn-secondary btn-small" id="dismiss-onboarding-btn">
			Hide this guide
		</button>
		<span class="onboarding-hint">This guide will permanently disappear once voting opens</span>
	</div>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
(function() {
	const banner = document.getElementById('onboarding-banner');
	const dismissBtn1 = document.getElementById('dismiss-onboarding');
	const dismissBtn2 = document.getElementById('dismiss-onboarding-btn');

	if (!banner) return;

	// Check localStorage first (instant client-side dismissal)
	const dismissalKey = 'onboarding_dismissed';
	const dismissedUntil = localStorage.getItem(dismissalKey);

	if (dismissedUntil) {
		const until = new Date(dismissedUntil);
		const now = new Date();
		if (now < until) {
			banner.style.display = 'none';
			return;
		} else {
			// Expired, remove from localStorage
			localStorage.removeItem(dismissalKey);
		}
	}

	function dismissBanner() {
		// Calculate 3 days from now
		const now = new Date();
		const until = new Date(now.getTime() + (3 * 24 * 60 * 60 * 1000));

		// Store in localStorage for instant dismissal
		localStorage.setItem(dismissalKey, until.toISOString());

		// Send to server for persistence across devices
		fetch('?action=dismiss_onboarding', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: 'csrf=' + encodeURIComponent('<?= h(csrf_token()) ?>')
		}).catch(err => {
			console.error('Failed to save dismissal to server:', err);
		});

		// Hide banner with animation
		banner.style.opacity = '0';
		banner.style.transform = 'translateY(-10px)';
		setTimeout(() => {
			banner.style.display = 'none';
		}, 300);
	}

	if (dismissBtn1) {
		dismissBtn1.addEventListener('click', dismissBanner);
	}
	if (dismissBtn2) {
		dismissBtn2.addEventListener('click', dismissBanner);
	}
})();
</script>
