<?php
declare(strict_types=1);
// public/admin/vote-key/save_mapping_pubkey.php
// Saves the X25519 public key for voter identity mapping (separate from main vote key)

header('Content-Type: application/json');
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/../../../php-survey-backend/includes/helpers.php';
require_once __DIR__ . '/../../../php-survey-backend/includes/config_loader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// Check if anonymous voter export is enabled
if (!($config['anonymous_voter_export'] ?? false)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Anonymous voter export is not enabled. Enable it in Vote Settings first.']));
}

$input = json_decode(file_get_contents('php://input'), true);

// SECURITY: CSRF protection
if (!isset($input['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $input['csrf'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'CSRF validation failed']));
}

if (!isset($input['pubkey_b64'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing pubkey_b64']));
}

$pubkey_b64 = $input['pubkey_b64'];
$pubkey_bin = base64_decode($pubkey_b64, true);

if ($pubkey_bin === false) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid base64 encoding']));
}

$actual_length = strlen($pubkey_bin);
if ($actual_length !== 32) {
    http_response_code(400);
    exit(json_encode(['error' => "Invalid pubkey length: expected 32 bytes, got $actual_length bytes"]));
}

// Mapping pubkey file path
$pubkeyFile = get_mapping_pubkey_path($config);

if (file_exists($pubkeyFile)) {
    http_response_code(409);
    exit(json_encode(['error' => 'Mapping pubkey already exists']));
}

// Create directory if needed
$dir = dirname($pubkeyFile);
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

// Atomic writing with flock
$fp = fopen($pubkeyFile, 'x');
if (!$fp) {
    http_response_code(409);
    exit(json_encode(['error' => 'Mapping pubkey already exists']));
}

if (flock($fp, LOCK_EX)) {
    fwrite($fp, $pubkey_b64 . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(201);
    exit(json_encode(['success' => true]));
} else {
    fclose($fp);
    http_response_code(500);
    exit(json_encode(['error' => 'Lock error']));
}
