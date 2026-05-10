<?php
/**
 * Admin endpoint to fetch encrypted voter mapping entries
 * Returns a JSON array containing all mapping data (voter_id → email)
 * REQUIRES ADMIN AUTHENTICATION
 */

// Load security and configuration
require_once __DIR__ . '/../../php-survey-backend/includes/security.php';
require_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';

// SECURITY: Require admin authentication
require_admin_auth($config);

// Check if anonymous voter export is enabled
if (!($config['anonymous_voter_export'] ?? false)) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Anonymous voter export is not enabled']));
}

// Enforce anonymity: block mapping export while voting is open
require_once __DIR__ . '/../../php-survey-backend/includes/helpers.php';
$voting_status = get_voting_status($config['voting_state_json'], $config['votes_dir']);
if ($voting_status['is_open']) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Voter mapping can only be downloaded after voting closes']));
}

$mapping_file = get_voter_mapping_path($config);

if (!file_exists($mapping_file)) {
    http_response_code(404);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'No voter mapping file found']));
}

$content = file_get_contents($mapping_file);
if ($content === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Failed to read voter mapping file']));
}

// Each line is a base64 encoded encrypted entry
$lines = array_filter(explode("\n", trim($content)), function($line) {
    return !empty(trim($line));
});

// Stream the response
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

echo '[';

$first = true;
foreach ($lines as $index => $line) {
    if (!$first) {
        echo ',';
    }

    // Each line is already base64 encoded encrypted data
    echo json_encode([
        'index' => $index,
        'content' => trim($line)
    ]);

    $first = false;

    // Flush output buffer
    if (ob_get_level() > 0) {
        ob_flush();
        flush();
    }
}

echo ']';
?>
