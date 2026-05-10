<?php
declare(strict_types=1);

// Load admin helpers (includes security.php, helpers.php, and admin-specific functions)
require_once __DIR__ . '/../../../php-survey-backend/includes/admin_helpers.php';

// Initialize secure session
init_secure_session();

// Configure error logging and rotate logs
$log_dir = __DIR__ . '/../../../php-survey-backend/logs';
setup_error_logging($log_dir);
rotate_logs($log_dir);

/* ===================== LOAD CONFIG ===================== */
$config_path = __DIR__ . '/../../../php-survey-backend/config/config.php';
if (!file_exists($config_path)) { http_response_code(500); exit('Critical Error: Configuration file not found.'); }
$config = require $config_path;

/* ======== SECURITY: TRUSTED HOSTS + FIXED BASE URL ======== */
$trusted = $config['trusted_hosts'] ?? [];
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($trusted && !in_array($host, $trusted, true)) { http_response_code(400); exit('Unauthorized host'); }
$base_url = resolve_base_url($config);

/* ===================== SECURITY: ADMIN GUARD (STRICT) ===================== */
require_once __DIR__ . '/../../../php-survey-backend/includes/admin_guard_strict.php';

/* ======== SECRET GUARD ======== */
require_configured_secret($config);

/* ======== ENSURE DATA DIR ======== */
if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0750, true) && !is_dir($config['data_dir'])) {
    http_response_code(500); exit('Critical Error: Unable to create data directory.');
}

// Shared CSP for admin console (allows Turnstile + Quill/CDN assets)
send_default_csp($config);

/* ===================== ROUTER ===================== */
$announcement_file = $config['announcement_json'];
$action = $_POST['action'] ?? 'view';
$flash  = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

if ($action === 'disconnect') {
    require_csrf($_POST['csrf'] ?? '');
    unset($_SESSION['admin_email'], $_SESSION['voter_email'], $_SESSION['last_activity']);
    $_SESSION['flash'] = ['ok', 'Disconnected.'];
    header('Location: ' . $base_url); exit;
}

if ($action === 'update_announcement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf'] ?? '');

    $enabled = ($_POST['enabled'] ?? 'false') === 'true';
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';

    if ($enabled && empty($content)) {
        $_SESSION['flash'] = ['err', 'The announcement content cannot be empty if it is enabled.'];
        header('Location: index.php'); exit;
    }

    $announcement = [
        'enabled' => $enabled,
        'title' => $title,
        'content' => $content
    ];

    if (save_json_file($announcement_file, $announcement)) {
        $_SESSION['flash'] = ['ok', 'Announcement updated successfully.'];
    } else {
        $_SESSION['flash'] = ['err', 'Error updating the announcement.'];
    }
    header('Location: index.php'); exit;
}

$default_announcement_content = '<p>Welcome to the election. Please read the following information carefully before proceeding to vote.</p>';
$announcement = load_json_file($announcement_file);
$ann_enabled = (bool)($announcement['enabled'] ?? false);
$ann_title = trim((string)($announcement['title'] ?? ''));
$ann_content = $announcement['content'] ?? $default_announcement_content;
if ($ann_content === '') {
    $ann_content = $default_announcement_content;
}
$ann_display_title = $ann_title !== '' ? $ann_title : 'Important Information';
$ann_preview_chip_text = $ann_enabled ? 'Visible to voters' : 'Not shown to voters';
$ann_preview_status_text = $ann_enabled ? '' : 'Draft';

/* ===================== VIEWS ===================== */
function header_html(string $title, string $app, array $config = [], string $css_path = '../../assets/css/style.css?v=2'): void {
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="' . h($css_path) . '">';
    echo '<link rel="stylesheet" href="../../assets/css/admin.css?v=2">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">';
    echo '<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>';
    echo '<style>';
    echo <<<CSS
body.announcement-settings {
  background: var(--bg);
}

body.announcement-settings .card {
  max-width: min(1100px, 100%);
  width: min(1100px, 100%);
  padding: clamp(22px, 2.4vw, 34px);
}

body.announcement-settings .back-link a {
  color: var(--primary);
  background: linear-gradient(120deg, rgba(108,99,255,0.15), rgba(108,99,255,0.4));
  border-color: rgba(108,99,255,0.35);
  box-shadow: 0 14px 36px rgba(87,79,211,0.25);
}

body.announcement-settings .back-link a:hover {
  color: #fff;
  background: linear-gradient(120deg, rgba(108,99,255,0.28), rgba(108,99,255,0.5));
  box-shadow: 0 20px 44px rgba(87,79,211,0.35);
}

.announcement-layout {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-top: clamp(24px, 3vw, 32px);
}

.announcement-panel {
  background: #fff;
  border: 1px solid rgba(15,23,42,0.08);
  border-radius: 20px;
  padding: clamp(16px, 2vw, 22px);
  box-shadow: 0 18px 40px rgba(15,23,42,0.08);
  display: flex;
  flex-direction: column;
  gap: clamp(12px, 1.8vw, 18px);
  animation: floatUp 0.6s ease forwards;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.announcement-panel:hover {
  transform: translateY(-2px);
  box-shadow: 0 28px 60px rgba(99,102,241,0.18);
}

.panel-header {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.panel-eyebrow {
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.1em;
  color: color-mix(in srgb, var(--primary) 65%, var(--muted));
  font-weight: 600;
}

.panel-header h3 {
  margin: 0;
  font-size: clamp(1.2rem, 2vw, 1.45rem);
  font-weight: 650;
  letter-spacing: -0.01em;
}

.panel-tip {
  background: var(--bg-accent);
  border: 1px solid rgba(15,23,42,0.08);
  border-radius: 16px;
  padding: 10px 14px;
  font-size: 0.95rem;
  color: var(--muted);
  line-height: 1.5;
}

#announcement-form {
  display: flex;
  flex-direction: column;
  gap: clamp(10px, 1.8vw, 14px);
}

#announcement-form .announcement-form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: clamp(12px, 2vw, 16px);
}

#announcement-form .announcement-field {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px 18px;
  background: var(--bg-accent);
  border: 1px solid rgba(15,23,42,0.08);
  border-radius: 14px;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}

#announcement-form .announcement-field label {
  font-weight: 650;
  color: var(--text);
  letter-spacing: -0.01em;
  margin-bottom: 4px;
}

#announcement-form .announcement-field-full {
  grid-column: 1 / -1;
}

#announcement-form .announcement-field .muted {
  font-size: 0.9rem;
  margin-bottom: 6px;
}

#announcement-form select,
#announcement-form input[type="text"] {
  border-radius: 12px;
  height: 44px;
  width: 100%;
  max-width: 100%;
  border: 1px solid rgba(15,23,42,0.14);
  background: #fff;
  padding: 0 12px;
  font-size: 0.95rem;
  color: var(--text);
  box-sizing: border-box;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f172a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 16px;
  padding-right: 40px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

#announcement-form select:focus {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}

#announcement-form input[type="text"] {
  background-image: none;
  padding-right: 12px;
  white-space: normal;
}

#announcement-form .announcement-field .quill-wrapper {
  margin-top: 2px;
  width: 100%;
  max-width: 100%;
  overflow: hidden;
}

#announcement-submit-btn {
  width: 100%;
  margin-top: clamp(8px, 1.2vw, 12px);
}

.quill-wrapper {
  width: 100%;
  max-width: 100%;
  overflow: hidden;
}

.ql-toolbar.ql-snow {
  background: var(--bg-accent);
  border-color: rgba(15,23,42,0.14);
  color: var(--text);
  border-radius: 14px 14px 0 0;
  padding: clamp(8px, 1.4vw, 12px) clamp(10px, 1.6vw, 14px);
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  box-shadow: 0 2px 6px rgba(15,23,42,0.05);
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}

.ql-toolbar.ql-snow .ql-formats {
  margin-right: 0.5rem;
  padding-right: 0.5rem;
  border-right: 1px solid rgba(15,23,42,0.08);
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.ql-toolbar.ql-snow .ql-formats:last-child {
  border-right: none;
  margin-right: 0;
  padding-right: 0;
}

.ql-toolbar.ql-snow button,
.ql-toolbar.ql-snow .ql-picker-label {
  border-radius: 8px;
  border: 1px solid transparent;
  transition: all 0.15s ease;
  color: var(--text);
}

.ql-toolbar.ql-snow button:hover,
.ql-toolbar.ql-snow .ql-picker-label:hover {
  background: rgba(99,102,241,0.08);
  border-color: rgba(99,102,241,0.15);
}

.ql-toolbar.ql-snow button.ql-active,
.ql-toolbar.ql-snow .ql-picker-label.ql-active {
  background: rgba(99,102,241,0.12);
  border-color: var(--primary);
  color: var(--primary);
  box-shadow: 0 0 0 1px rgba(99,102,241,0.2);
}

.ql-container.ql-snow {
  background: #fff;
  border-color: rgba(15,23,42,0.12);
  color: var(--text);
  border-radius: 0 0 14px 14px;
  min-height: 260px;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  overflow: hidden;
}

.ql-editor {
  min-height: 200px;
  color: var(--text);
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  overflow-x: hidden;
  overflow-y: auto;
}

.ql-editor a {
  color: var(--primary);
}

.preview-status-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
}

.preview-hint {
  font-size: 0.9rem;
  color: var(--muted);
}

#preview-box {
  border: 2px solid rgba(99,102,241,0.15);
  padding: clamp(18px, 2vw, 24px);
  background: var(--bg-accent);
  color: var(--text);
  border-radius: 22px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.7), 0 25px 50px rgba(99,102,241,0.12);
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  min-height: 320px;
}

#preview-title {
  margin: 0;
  font-size: clamp(1.35rem, 2.2vw, 1.6rem);
  font-weight: 650;
  letter-spacing: -0.01em;
}

#preview-content {
  flex: 1;
  overflow-y: auto;
  line-height: 1.7;
}

.preview-title-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
}

.preview-pill {
  padding: 0.35rem 0.9rem;
  border-radius: 999px;
  background: rgba(99,102,241,0.15);
  color: var(--primary);
  font-weight: 600;
  font-size: 0.8rem;
}

#preview-box button {
  width: 100%;
}

.preview-footer {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
}

.preview-footer small {
  color: var(--muted);
  text-align: center;
}

.announcement-guidance {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: clamp(16px, 2vw, 24px);
  margin-top: clamp(24px, 3vw, 32px);
}

.announcement-guidance-card {
  background: #fff;
  border-radius: 20px;
  border: 1px solid rgba(15,23,42,0.08);
  box-shadow: 0 16px 36px rgba(15,23,42,0.08);
  padding: clamp(16px, 2vw, 22px);
  animation: floatUp 0.6s ease forwards;
}

.announcement-guidance-card h3 {
  margin: 0 0 0.5rem;
  font-size: clamp(1rem, 1.6vw, 1.2rem);
  font-weight: 650;
}

.announcement-guidance-card p {
  margin: 0;
  color: var(--muted);
  line-height: 1.6;
}

.announcement-checklist {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.announcement-checklist li {
  position: relative;
  padding-left: 1.8rem;
  line-height: 1.6;
  color: var(--text);
}

.announcement-checklist li::before {
  content: "\\2713";
  position: absolute;
  left: 0;
  top: 0.15rem;
  color: var(--primary);
  font-weight: 700;
}

.status-badge.status-muted {
  background: rgba(15,23,42,0.08);
  border-color: rgba(15,23,42,0.16);
  color: var(--muted);
  box-shadow: 0 2px 8px rgba(15,23,42,0.12);
}

@media (max-width: 520px) {
  #preview-box {
    min-height: 280px;
  }
  
  .announcement-panel {
    overflow: visible;
  }
  
  #announcement-form .announcement-field {
    overflow: visible;
    position: relative;
    z-index: 1;
  }
  
  #announcement-form select {
    position: relative;
    z-index: 100;
  }
}
CSS;
    echo '</style>';
    echo '</head><body class="announcement-settings"><div class="main-wrapper"><div class="card">';
    echo '<h1 class="admin-main-title">' . h($app) . ' — Announcement</h1>';
}

header_html('Announcement Configuration', $config['app_name'], $config);
admin_flash_html($flash);
?>

<div class="back-link">
    <a href="../vote-settings/index.php">Back to vote settings</a>
</div>

<h2 class="admin-section-header">Announcement editor &amp; preview</h2>

<div class="admin-info-box">
    <strong>How this page works:</strong><br>
    Craft the onboarding message voters read immediately after logging in. Use the visual editor to format links, highlights, and lists; the preview mirrors the public experience.
</div>

<div class="announcement-layout">
    <section class="announcement-panel form-panel" style="margin-top: 0px;">
        <div class="panel-header">
            <span class="panel-eyebrow">Editor</span>
            <h3>Content and status</h3>
            <p class="muted">Switch between visible and hidden modes while writing the announcement that greets every voter.</p>
        </div>

        <form method="post" id="announcement-form">
            <input type="hidden" name="action" value="update_announcement">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

            <div class="announcement-form-grid">
                <div class="announcement-field">
                    <label for="enabled-select">Announcement status</label>
                    <span class="muted">Toggle whether voters see this screen before the ballot.</span>
                    <select name="enabled" id="enabled-select">
                        <option value="false" <?= !$ann_enabled ? 'selected' : '' ?>>Disabled</option>
                        <option value="true" <?= $ann_enabled ? 'selected' : '' ?>>Enabled</option>
                    </select>
                </div>

                <div class="announcement-field">
                    <label for="announcement-title">Announcement title (optional)</label>
                    <span class="muted">Keep it short, like “Important Information.”</span>
                    <input type="text" id="announcement-title" name="title" value="<?= h($ann_title) ?>" placeholder="Ex: Important Information">
                </div>

                <div class="announcement-field announcement-field-full">
                    <label for="announcement-editor">Announcement content (HTML allowed)</label>
                    <span class="muted">Use the rich text editor for lists, links, and highlights.</span>
                    <div class="quill-wrapper">
                        <div id="announcement-editor"><?= $ann_content ?></div>
                        <input type="hidden" id="announcement-content" name="content" value="<?= h($ann_content) ?>">
                    </div>
                </div>
            </div>

            <div class="panel-tip">
                <strong>Need to pause the message?</strong> Switch to <em>Disabled</em> to send voters directly to the ballot.
            </div>

            <button type="submit" id="announcement-submit-btn">
                Save announcement
            </button>
        </form>
    </section>

    <section class="announcement-panel preview-panel" style="margin-top: 0px;">
        <div class="panel-header">
            <span class="panel-eyebrow">Preview</span>
            <div class="preview-status-row">
                <span class="status-badge <?= $ann_enabled ? 'status-open' : 'status-muted' ?>" id="preview-status-chip">
                    <?= h($ann_preview_chip_text) ?>
                </span>
                <?php if (!$ann_enabled): ?>
                <span class="preview-hint" id="preview-status-label"><?= h($ann_preview_status_text) ?></span>
                <?php endif; ?>
            </div>
            <p class="muted">Updates instantly — great for quick copy reviews or mobile spot checks.</p>
        </div>

        <div id="preview-box">
            <div class="preview-title-row">
                <h2 id="preview-title"><?= h($ann_display_title) ?></h2>
            </div>
            <div id="preview-content"><?= $ann_content ?></div>
            <div class="preview-footer">
                <button type="button">Continue to vote</button>
                <small>This is the button voters tap after reading the announcement.</small>
            </div>
        </div>
    </section>
</div>

<p class="admin-form-note">
    <strong>Tip:</strong> This editor supports headings, links, lists, and inline HTML. Always review the preview before sharing the admin link with your team.
</p>

<script nonce="<?= h(csp_nonce()) ?>">
let announcementQuill = null;
document.addEventListener('DOMContentLoaded', function() {
    const editorElement = document.getElementById('announcement-editor');
    const hiddenInput = document.getElementById('announcement-content');
    const titleInput = document.querySelector('input[name="title"]');
    const statusSelect = document.getElementById('enabled-select');

    if (editorElement && hiddenInput && window.Quill) {
        announcementQuill = new Quill('#announcement-editor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ header: [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ color: [] }, { background: [] }],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    [{ script: 'sub' }, { script: 'super' }],
                    [{ align: [] }],
                    ['blockquote', 'code-block'],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });

        const startingHtml = hiddenInput.value || editorElement.innerHTML || '';
        if (startingHtml) {
            announcementQuill.clipboard.dangerouslyPasteHTML(startingHtml);
        }

        announcementQuill.on('text-change', function() {
            hiddenInput.value = announcementQuill.root.innerHTML;
            updateAnnouncementPreview();
        });
    }

    if (titleInput) {
        titleInput.addEventListener('input', updateAnnouncementPreview);
    }
    if (statusSelect) {
        statusSelect.addEventListener('change', updateAnnouncementStatusDisplay);
    }

    updateAnnouncementPreview();
    updateAnnouncementStatusDisplay();
});

function updateAnnouncementPreview() {
    const titleInput = document.querySelector('input[name="title"]');
    const previewTitle = document.getElementById('preview-title');
    const previewContent = document.getElementById('preview-content');
    const hiddenInput = document.getElementById('announcement-content');

    if (previewTitle && titleInput) {
        const titleValue = titleInput.value.trim() || 'Important Information';
        previewTitle.textContent = titleValue;
    }
    if (previewContent) {
        const html = hiddenInput ? hiddenInput.value : '';
        previewContent.innerHTML = html || '<p>Welcome to this election.</p>';
    }
}

function updateAnnouncementStatusDisplay() {
    const statusSelect = document.getElementById('enabled-select');
    if (!statusSelect) return;
    const isEnabled = statusSelect.value === 'true';

    const previewChip = document.getElementById('preview-status-chip');
    const previewLabel = document.getElementById('preview-status-label');

    const previewChipText = isEnabled ? 'Visible to voters' : 'Not shown to voters';
    const previewLabelText = isEnabled ? '' : 'Draft';

    if (previewChip) {
        previewChip.textContent = previewChipText;
        previewChip.classList.toggle('status-open', isEnabled);
        previewChip.classList.toggle('status-muted', !isEnabled);
    }
    if (previewLabel) {
        if (isEnabled) {
            previewLabel.style.display = 'none';
        } else {
            previewLabel.style.display = 'inline';
            previewLabel.textContent = previewLabelText;
        }
    }
}
</script>

<?php
$is_admin_page = true; // Tell footer we don't have flow-stack
include __DIR__ . '/../../footer.php';
?>
