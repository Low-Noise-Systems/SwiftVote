<?php
declare(strict_types=1);
// public/admin/vote-key/check_pubkey.php
// Checks if the public key already exists

header('Content-Type: application/json');
require_once __DIR__ . '/admin_guard.php';

$pubkeyFile = __DIR__ . "/../../../php-survey-backend/data/pubkey.txt";

$response = ['exists' => false];

if (file_exists($pubkeyFile)) {
    $pubkeyB64 = trim(file_get_contents($pubkeyFile));
    $pubkeyBin = base64_decode($pubkeyB64, true);

    if ($pubkeyBin === false || strlen($pubkeyBin) !== 32) {
        http_response_code(500);
        exit(json_encode(['error' => 'Invalid pubkey contents']));
    }

    $response['exists'] = true;
    $response['pubkey_b64'] = $pubkeyB64;
    $response['pubkey_hex'] = bin2hex($pubkeyBin);
    $response['saved_at'] = date(DATE_ATOM, filemtime($pubkeyFile));
}

http_response_code($response['exists'] ? 200 : 404);
exit(json_encode($response));
