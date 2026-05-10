<?php
/**
 * Admin endpoint to list all vote files
 * Returns JSON array of vote filenames for download and decryption
 * REQUIRES ADMIN AUTHENTICATION
 */

// Load security and configuration
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/config_loader.php';

// SECURITY: Require admin authentication (prevents unauthorized access)
require_admin_auth($config);

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

$vote_list = [];
foreach ($files as $file) {
    $vote_list[] = [
        'filename' => basename($file),
        'timestamp' => filemtime($file),
        'size' => filesize($file)
    ];
}

// Sort by timestamp (oldest first)
usort($vote_list, function($a, $b) {
    return $a['timestamp'] <=> $b['timestamp'];
});

header('Content-Type: application/json');
echo json_encode([
    'votes' => $vote_list,
    'count' => count($vote_list)
], JSON_PRETTY_PRINT);
?>
