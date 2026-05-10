<?php
// Load simple SMTP class
require_once __DIR__ . '/simple_smtp.php';
// Debug helpers (for suppressing mail in local debug mode)
if (!function_exists('is_debug_enabled')) {
    require_once __DIR__ . '/debug.php';
}

/**
 * Load a single SMTP server configuration from smtp_mailboxes.json
 * Accepts either a plain object or an array and picks the first entry.
 */
function load_smtp_server(array $config): ?array {
    $mailboxesFile = $config['smtp_mailboxes_file'] ?? '';
    if (!$mailboxesFile || !file_exists($mailboxesFile)) {
        error_log('SMTP: smtp_mailboxes.json not found');
        return null;
    }

    $jsonContent = file_get_contents($mailboxesFile);
    if ($jsonContent === false) {
        error_log('SMTP: Failed to read smtp_mailboxes.json');
        return null;
    }

    $mailboxes = json_decode($jsonContent, true);
    if (!is_array($mailboxes) || empty($mailboxes)) {
        error_log('SMTP: smtp_mailboxes.json is empty or invalid');
        return null;
    }

    // Support both a single object and an array of servers. If multiple are
    // provided, only the first one is used.
    if (isset($mailboxes['host'])) {
        $mailbox = $mailboxes;
    } else {
        $mailbox = reset($mailboxes);
        if (count($mailboxes) > 1) {
            error_log('SMTP: Multiple servers configured; only the first entry will be used');
        }
    }

    if (!is_array($mailbox) || empty($mailbox['host']) || empty($mailbox['username']) || empty($mailbox['password']) || empty($mailbox['from']['address'])) {
        error_log('SMTP: smtp_mailboxes.json is missing required fields (host, username, password, from.address)');
        return null;
    }

    return $mailbox;
}

/* ===================== EMAIL SENDING ===================== */

/**
 * Send email using hosting mode (PHP mail() function)
 *
 * @param array $config Full application config
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $html HTML content
 * @return bool Success
 */
function send_email_hosting(array $config, string $to, string $subject, string $html): bool {
    if (!function_exists('mail')) {
        error_log('MAIL: mail() function unavailable');
        return false;
    }

    $smtpConfig = $config['smtp'];
    $fromAddr = $smtpConfig['from']['address'];
    $fromName = $smtpConfig['from']['name'] ?? '';
    $replyAddr = $smtpConfig['reply_to']['address'] ?? $fromAddr;
    $replyName = $smtpConfig['reply_to']['name'] ?? $fromName;

    $boundary = 'b_' . bin2hex(random_bytes(6));
    $fromHdr = $fromName ? mb_encode_mimeheader($fromName, 'UTF-8') . " <{$fromAddr}>" : $fromAddr;
    $replyHdr = $replyName ? mb_encode_mimeheader($replyName, 'UTF-8') . " <{$replyAddr}>" : $replyAddr;

    $headers = [
        "From: {$fromHdr}",
        "Reply-To: {$replyHdr}",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
    ];

    $text = strip_tags(preg_replace('/<br\s*\/?\>/i', "\n", $html));
    $parts = [
        "--{$boundary}",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
        "",
        chunk_split(base64_encode($text)),
        "--{$boundary}",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
        "",
        chunk_split(base64_encode($html)),
        "--{$boundary}--",
    ];

    $headersStr = implode("\r\n", $headers);
    $bodyStr = implode("\r\n", $parts);
    $subj = mb_encode_mimeheader($subject, 'UTF-8');
    $params = '-f' . escapeshellarg($fromAddr);

    $ok = mail($to, $subj, $bodyStr, $headersStr, $params);
    if (!$ok) {
        error_log('MAIL: mail() returned false');
    }
    return $ok;
}

/**
 * Send email using SMTP mode (SimpleSMTP with external SMTP)
 *
 * @param array $config Full application config
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $html HTML content
 * @return bool Success (true if ANY server succeeded)
 */
function send_email_smtp(array $config, string $to, string $subject, string $html): bool {
    $smtpServer = load_smtp_server($config);
    if ($smtpServer === null) {
        return false;
    }

    $serverKey = $smtpServer['host'] . ':' . ($smtpServer['username'] ?? $smtpServer['from']['address']);
    error_log("SMTP: Attempting to send via {$serverKey}");

    try {
        $smtp = new SimpleSMTP(
            $smtpServer['host'],
            $smtpServer['port'] ?? 587,
            $smtpServer['username'],
            $smtpServer['password'],
            $smtpServer['encryption'] ?? 'tls'
        );

        $fromAddr = $smtpServer['from']['address'];
        $fromName = $smtpServer['from']['name'] ?? '';
        $replyAddr = $smtpServer['reply_to']['address'] ?? $fromAddr;
        $replyName = $smtpServer['reply_to']['name'] ?? $fromName;
        $textBody = strip_tags(preg_replace('/<br\s*\/?\>/i', "\n", $html));

        $success = $smtp->send(
            $fromAddr,
            $fromName,
            $to,
            $subject,
            $html,
            $textBody,
            $replyAddr,
            $replyName
        );

        if ($success) {
            error_log("SMTP: Email sent successfully via {$fromAddr}");
            return true;
        }

        $error = $smtp->getLastError();
        error_log("SMTP: Failed to send via {$serverKey}: {$error}");
        return false;
    } catch (Exception $e) {
        error_log("SMTP: Exception sending via {$serverKey}: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email using configured mode (hosting or SMTP)
 * Main entry point for sending emails
 *
 * @param array $config Full application config
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $html HTML content
 * @return bool True if mail was sent successfully
 */
function send_smtp_mail(array $config, string $to, string $subject, string $html): bool {
    // In debug mode on localhost, skip sending emails to avoid spamming during development
    if (is_debug_enabled()) {
        error_log('DEBUG_MODE: Email sending suppressed (to=' . $to . ', subject=' . $subject . ')');
        return true;
    }

    $mode = $config['mail_mode'] ?? 'hosting';

    if ($mode === 'smtp') {
        return send_email_smtp($config, $to, $subject, $html);
    } else {
        return send_email_hosting($config, $to, $subject, $html);
    }
}

/* ===================== TEMPLATES ===================== */
function load_template(string $key, array $config): string {
    $path = $config['template_paths'][$key] ?? null;
    if (!$path || !is_file($path) || !is_readable($path)) return "Error: Template '{$key}' not found.";
    return file_get_contents($path);
}
function mail_subject(string $key, array $config): string {
    $tpl = $config['mail_subjects'][$key] ?? '';
    return strtr($tpl, ['{app}' => $config['app_name']]);
}
function render_verify_mail(string $link, array $config): string {
    $tpl = load_template('verify', $config);
    $min = (int) round(($config['token_ttl_secs'] ?? 3600) / 60);
    return strtr($tpl, ['{app}' => h($config['app_name']), '{link}' => h($link), '{minutes}' => (string)$min]);
}
function render_verify_admin_mail(string $link, array $config): string {
    $tpl = load_template('verify_admin', $config);
    $min = (int) round(($config['token_ttl_secs'] ?? 3600) / 60);
    return strtr($tpl, ['{app}' => h($config['app_name']), '{link}' => h($link), '{minutes}' => (string)$min]);
}
function render_receipt_mail(string $email, array $selections, array $config, string $voting_method = 'approval'): string {
    // Check if we should include candidate names
    $include_names = $config['include_candidate_names_in_receipt'] ?? false;

    if ($include_names) {
        // Use detailed template with candidate names
        $tpl = load_template('receipt', $config);

        // Generate list items based on voting method
        if ($voting_method === 'stv') {
            // STV: show numbered rankings
            $lis = '';
            $rank = 1;
            foreach ($selections as $s) {
                $lis .= '<li><strong>#' . $rank . '</strong> ' . h($s) . '</li>';
                $rank++;
            }
        } else {
            // Approval: simple bullet list
            $lis = '';
            foreach ($selections as $s) {
                $lis .= '<li>' . h($s) . '</li>';
            }
        }

        return strtr($tpl, [
            '{email}' => h($email),
            '{app}' => h($config['app_name']),
            '{timestamp}' => h(date('Y-m-d H:i:s')),
            '{selections_li}' => $lis,
        ]);
    } else {
        // Use generic template without candidate names
        $tpl = load_template('receipt_generic', $config);
        return strtr($tpl, [
            '{email}' => h($email),
            '{app}' => h($config['app_name']),
            '{timestamp}' => h(date('Y-m-d H:i:s')),
        ]);
    }
}
?>
