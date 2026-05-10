<?php
/**
 * Secure endpoint to retrieve individual vote files
 * Only allows access to .vote.enc files in the votes directory
 * REQUIRES ADMIN AUTHENTICATION
 */
declare(strict_types=1);

// Load security and configuration
require_once __DIR__ . '/../../php-survey-backend/includes/security.php';
$config = require_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';

// SECURITY: Require admin authentication (prevents unauthorized access)
require_admin_auth($config);

$filename = $_GET['file'] ?? '';

// Security validations
if (empty($filename)) {
    http_response_code(400);
    exit('Missing filename');
}

// Only allow .vote.enc files (prevent directory traversal)
if (!preg_match('/^[a-f0-9]{64}\.vote\.enc$/', $filename)) {
    http_response_code(400);
    exit('Invalid filename format');
}

// Construct safe file path
$votes_dir = $config['votes_dir'];
$file_path = $votes_dir . '/' . basename($filename);

// Check file exists
if (!file_exists($file_path) || !is_file($file_path)) {
    http_response_code(404);
    exit('Vote file not found');
}

// Return file contents (encrypted base64)
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($file_path);
?>
