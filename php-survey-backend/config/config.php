<?php
// Build base config array
$config = [
    // App
    'app_name'       => getenv('APP_NAME') ?: 'Community Election',
    'base_url'       => getenv('BASE_URL') ?: 'https://example.org/index.php', // set your real URL
    'trusted_hosts'  => array_map('trim', explode(',', getenv('TRUSTED_HOSTS') ?: 'example.org')),

    // Paths (outside webroot)
	'data_dir'       => __DIR__ . '/../vote_data',
	'votes_dir'      => __DIR__ . '/../vote_data/votes',
	'candidates_json' => __DIR__ . '/../vote_data/candidates.json',
	'candidate_images_dir' => dirname(__DIR__, 2) . '/public/assets/images/candidates',
	'candidate_images_web_path' => '/assets/images/candidates',
	'voting_state_json' => __DIR__ . '/../vote_data/voting_state.json',
	'vote_params_json' => __DIR__ . '/vote_params.json',
	'app_settings_json' => __DIR__ . '/app_settings.json',
	'announcement_json' => __DIR__ . '/../vote_data/announcement.json',
    'tokens_json'    => __DIR__ . '/../vote_data/tokens.json',
    'voter_emails'   => __DIR__ . '/voter_emails.txt',

    // Email templates (outside webroot)
    'template_paths' => [
        'verify'  => __DIR__ . '/../email_templates/verify.html',
        'verify_admin' => __DIR__ . '/../email_templates/verify_admin.html',
        'receipt' => __DIR__ . '/../email_templates/receipt.html',
        'receipt_generic' => __DIR__ . '/../email_templates/receipt_generic.html',
    ],

    'token_ttl_secs' => (int)(getenv('TOKEN_TTL_SECS') ?: 3600),
    'secret_key'     => getenv('SECRET_KEY'),

    // Email sending configuration
    // Two modes: 'hosting' or 'smtp'
    // - hosting: Use local sendmail via PHP mail() (configured via HOSTING_* env variables)
    // - smtp: Use a single external SMTP server (configured via smtp_mailboxes.json file only)
    'mail_mode' => getenv('MAIL_MODE') ?: 'hosting', // 'hosting' or 'smtp'

    // Hosting mode configuration (ONLY used when mail_mode='hosting')
    // Uses HOSTING_* environment variables from .env file
    // Sends via PHP mail() function with local sendmail
    'smtp' => [
        'from'     => ['address' => getenv('HOSTING_FROM_ADDRESS') ?: 'noreply@mondomaine.fr', 'name' => getenv('HOSTING_FROM_NAME') ?: 'Election Bot'],
        'reply_to' => ['address' => getenv('HOSTING_REPLY_TO_ADDRESS') ?: 'support@mondomaine.fr', 'name' => getenv('HOSTING_REPLY_TO_NAME') ?: 'Election Support'],
    ],

    // SMTP mode configuration (used when mail_mode='smtp')
    // JSON file containing SMTP server configuration with credentials
    'smtp_mailboxes_file' => __DIR__ . '/smtp_mailboxes.json',

    'mail_subjects' => [
        'verify'  => '[{app}] Verify your address',
        'verify_admin' => '[{app}] Admin verification link',
        'receipt' => '[{app}] Vote recorded',
    ],

    // Neutral confirmation shown after link request (always the same to avoid leaks)
    'request_ack_msg' =>
        "If your email address is authorized (registered on Slack before the voting period begins), you will receive a verification email. " .
        "If you have already voted, you will not receive a new email." .
        "<div class='flash__warning'>⚠️ <strong>Didn't receive an email?</strong> Check your spam/junk folder. Outlook.com users especially should check their junk mail.</div>",

    // Cloudflare Turnstile (disabled by default, can be enabled in admin settings)
    'turnstile_enabled' => false,
    'turnstile_site_key' => getenv('TURNSTILE_SITE_KEY') ?: '',
    'turnstile_secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: '',
];

// Load app/mail settings from JSON file (runtime-mutable configuration)
// This allows admin to modify these values without writing to .env file
$app_settings_file = $config['app_settings_json'];
$app_settings_defaults = [
    'app_name' => $config['app_name'],
    'mail_mode' => $config['mail_mode'],
    'hosting_from_address' => $config['smtp']['from']['address'] ?? '',
    'hosting_from_name' => $config['smtp']['from']['name'] ?? '',
    'hosting_reply_to_address' => $config['smtp']['reply_to']['address'] ?? '',
    'hosting_reply_to_name' => $config['smtp']['reply_to']['name'] ?? '',
    'turnstile_enabled' => $config['turnstile_enabled'],
    'turnstile_site_key' => $config['turnstile_site_key'],
    'turnstile_secret_key' => $config['turnstile_secret_key'],
];
$app_settings = $app_settings_defaults;

if (!file_exists($app_settings_file)) {
    $written = file_put_contents($app_settings_file, json_encode($app_settings_defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($written === false) {
        error_log('CONFIG: Unable to write default app_settings.json');
    }
} else {
    $json_content = file_get_contents($app_settings_file);
    if ($json_content === false) {
        error_log('CONFIG: Unable to read app_settings.json');
    } else {
        $loaded_settings = json_decode($json_content, true);
        if (is_array($loaded_settings)) {
            $app_settings = array_merge($app_settings_defaults, $loaded_settings);
        }
    }
}

if (isset($app_settings['app_name']) && trim((string)$app_settings['app_name']) !== '') {
    $config['app_name'] = (string)$app_settings['app_name'];
}

$mail_mode = (string)($app_settings['mail_mode'] ?? '');
if ($mail_mode === 'hosting' || $mail_mode === 'smtp') {
    $config['mail_mode'] = $mail_mode;
}

if (array_key_exists('hosting_from_address', $app_settings)) {
    $from_address = trim((string)$app_settings['hosting_from_address']);
    if ($from_address !== '') {
        $config['smtp']['from']['address'] = $from_address;
    }
}

if (array_key_exists('hosting_from_name', $app_settings)) {
    $config['smtp']['from']['name'] = (string)$app_settings['hosting_from_name'];
}

if (array_key_exists('hosting_reply_to_address', $app_settings)) {
    $reply_address = trim((string)$app_settings['hosting_reply_to_address']);
    if ($reply_address === '') {
        $reply_address = $config['smtp']['from']['address'];
    }
    $config['smtp']['reply_to']['address'] = $reply_address;
}

if (array_key_exists('hosting_reply_to_name', $app_settings)) {
    $reply_name = (string)$app_settings['hosting_reply_to_name'];
    if ($reply_name === '') {
        $reply_name = $config['smtp']['from']['name'] ?? '';
    }
    $config['smtp']['reply_to']['name'] = $reply_name;
}

// Turnstile (captcha) settings from app_settings.json
if (array_key_exists('turnstile_enabled', $app_settings)) {
    $config['turnstile_enabled'] = (bool)$app_settings['turnstile_enabled'];
}
if (array_key_exists('turnstile_site_key', $app_settings)) {
    $site_key = trim((string)$app_settings['turnstile_site_key']);
    if ($site_key !== '' && $site_key !== 'your_turnstile_site_key_here') {
        $config['turnstile_site_key'] = $site_key;
    }
}
if (array_key_exists('turnstile_secret_key', $app_settings)) {
    $secret_key = trim((string)$app_settings['turnstile_secret_key']);
    if ($secret_key !== '' && $secret_key !== 'your_turnstile_secret_key_here') {
        $config['turnstile_secret_key'] = $secret_key;
    }
}

// Load vote parameters from JSON file (runtime-mutable configuration)
// This allows admin to modify these values without writing to .env file
$vote_params_file = __DIR__ . '/vote_params.json';
$vote_params = [
    'min_picks' => 3,
    'max_picks' => 9,
    'allow_multiple_votes' => false,
    'include_candidate_names_in_receipt' => false,
    'show_manifestos' => true,
    'anonymous_voter_export' => false,
    'voting_method' => 'approval',        // 'approval' or 'stv'
    'stv_require_full_ranking' => false,  // If true, must rank all candidates
    'stv_min_rankings' => 1               // Minimum rankings required (when partial allowed)
]; // defaults

if (!file_exists($vote_params_file)) {
    // Auto-create the file with default values if it doesn't exist
    $written = file_put_contents($vote_params_file, json_encode($vote_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($written === false) {
        error_log('CONFIG: Unable to write default vote_params.json');
    }
} else {
    $json_content = file_get_contents($vote_params_file);
    if ($json_content === false) {
        error_log('CONFIG: Unable to read vote_params.json');
    } else {
        $loaded_params = json_decode($json_content, true);
        if (is_array($loaded_params)) {
            $vote_params = array_merge($vote_params, $loaded_params);
        }
    }
}

// Merge vote parameters into config
$config['min_picks'] = (int)$vote_params['min_picks'];
$config['max_picks'] = (int)$vote_params['max_picks'];
$config['allow_multiple_votes'] = (bool)$vote_params['allow_multiple_votes'];
$config['include_candidate_names_in_receipt'] = (bool)$vote_params['include_candidate_names_in_receipt'];
$config['show_manifestos'] = (bool)($vote_params['show_manifestos'] ?? true);
$config['anonymous_voter_export'] = (bool)($vote_params['anonymous_voter_export'] ?? false);
$config['voting_method'] = in_array($vote_params['voting_method'] ?? 'approval', ['approval', 'stv']) ? $vote_params['voting_method'] : 'approval';
$config['stv_require_full_ranking'] = (bool)($vote_params['stv_require_full_ranking'] ?? false);
$config['stv_min_rankings'] = max(1, (int)($vote_params['stv_min_rankings'] ?? 1));

return $config;
