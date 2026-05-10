<?php
/**
 * Admin endpoint to fetch all encrypted votes in a single request
 * Returns a JSON array containing all vote data
 * REQUIRES ADMIN AUTHENTICATION
 */

// Load security and configuration
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/config_loader.php';

// SECURITY: Require admin authentication
require_admin_auth($config);

// Enforce anonymity: block vote export while voting is open in anonymous mode
require_once __DIR__ . '/../includes/helpers.php';
$voting_status = get_voting_status($config['voting_state_json'], $config['votes_dir']);
if ($config['anonymous_voter_export'] && $voting_status['is_open']) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Votes can only be downloaded after voting closes (anonymous mode enabled)']));
}

$votes_dir = $config['votes_dir'];

if (!is_dir($votes_dir)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Votes directory not found']));
}

// Get all .vote.enc files
$files = glob($votes_dir . '/*.vote.enc');

if ($files === false) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to list votes']));
}

// Stream the response to handle large amounts of data without memory issues
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

echo '[';

$first = true;
foreach ($files as $file) {
    if (!$first) {
        echo ',';
    }
    
    $content = file_get_contents($file);
    // The content is base64 encoded encrypted data. 
    // We send it as is, wrapped in a JSON object.
    echo json_encode([
        'filename' => basename($file),
        'content' => trim($content)
    ]);
    
    $first = false;
    
    // Flush output buffer to keep the stream moving
    if (ob_get_level() > 0) {
        ob_flush();
        flush();
    }
}

echo ']';
?>
