<?php
declare(strict_types=1);
// public/admin/vote-key/save_pubkey.php
// Saves the X25519 public key for the project

header('Content-Type: application/json');
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/../../../php-survey-backend/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$input = json_decode(file_get_contents('php://input'), true);

// SECURITY: CSRF protection - critical for preventing attackers from setting a malicious public key
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

// Fichier unique pour le projet
$pubkeyFile = __DIR__ . "/../../../php-survey-backend/data/pubkey.txt";

if (file_exists($pubkeyFile)) {
    http_response_code(409);
    exit(json_encode(['error' => 'Pubkey already exists']));
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
    exit(json_encode(['error' => 'Pubkey already exists']));
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
