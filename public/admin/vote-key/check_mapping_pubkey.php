<?php
declare(strict_types=1);
// public/admin/vote-key/check_mapping_pubkey.php
// Checks if the mapping public key already exists

header('Content-Type: application/json');
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/../../../php-survey-backend/includes/helpers.php';
require_once __DIR__ . '/../../../php-survey-backend/includes/config_loader.php';

$pubkeyFile = get_mapping_pubkey_path($config);

$response = ['exists' => false];

if (file_exists($pubkeyFile)) {
    $pubkeyB64 = trim(file_get_contents($pubkeyFile));
    $pubkeyBin = base64_decode($pubkeyB64, true);

    if ($pubkeyBin === false || strlen($pubkeyBin) !== 32) {
        http_response_code(500);
        exit(json_encode(['error' => 'Invalid mapping pubkey contents']));
    }

    $response['exists'] = true;
    $response['pubkey_b64'] = $pubkeyB64;
    $response['pubkey_hex'] = bin2hex($pubkeyBin);
    $response['saved_at'] = date(DATE_ATOM, filemtime($pubkeyFile));
}

http_response_code($response['exists'] ? 200 : 404);
exit(json_encode($response));
