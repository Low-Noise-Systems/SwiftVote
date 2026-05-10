<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/rate_limiter.php';

$storage_dir = __DIR__ . '/../data/rate_limits_test_suite';
if (!is_dir($storage_dir)) {
    mkdir($storage_dir, 0755, true);
} else {
    foreach (glob($storage_dir . '/*') as $file) {
        if (file_exists($file)) unlink($file);
    }
}

$limiter = new FileBasedRateLimiter($storage_dir);

function assert_true($condition, string $message): void {
    if (!$condition) {
        fail($message);
    }
}

function assert_equals($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fail($message . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ')');
    }
}

function assert_numeric_equals($expected, $actual, float $delta, string $message): void {
    if (abs($expected - $actual) > $delta) {
        fail($message . " (expected {$expected}, got {$actual})");
    }
}

function fail(string $message): void {
    fwrite(STDERR, "[FAIL] {$message}\n");
    cleanup();
    exit(1);
}

function cleanup(): void {
    global $storage_dir;
    foreach (glob($storage_dir . '/*') as $file) {
        if (file_exists($file)) unlink($file);
    }
    if (is_dir($storage_dir)) {
        rmdir($storage_dir);
    }
}

register_shutdown_function('cleanup');

// Test 1: First valid request should succeed and reduce score by 10
$ip_valid = '203.0.113.10';
$valid_email = 'admin@example.com';
$res = $limiter->check_access($ip_valid, $valid_email, true);
assert_true($res['send_email'] === true, 'Valid email should result in email send');
assert_true($res['blocked'] === false, 'Valid request should not be blocked');
assert_equals(90.0, $res['score'], 'First valid request should reduce score to 90');

// Test 2: Cooldown should trigger with penalty but without blocking
$res_cooldown = $limiter->check_access($ip_valid, $valid_email, true);
assert_equals('cooldown', $res_cooldown['reason'], 'Second request should trigger cooldown');
assert_true($res_cooldown['send_email'] === false, 'Cooldown should prevent sending another email');
assert_equals(85.0, $res_cooldown['score'], 'Cooldown penalty should reduce score by 5 points');
assert_true(isset($res_cooldown['cooldown_seconds']) && $res_cooldown['cooldown_seconds'] > 0, 'Cooldown should return remaining seconds');

// Test 3: Six invalid attempts should trigger blocking with accurate wait time
$ip_invalid = '203.0.113.20';
for ($i = 1; $i <= 5; $i++) {
    $res_invalid = $limiter->check_access($ip_invalid, "invalid{$i}@example.com", false);
    assert_true($res_invalid['blocked'] === false, "Invalid attempt {$i} should not be blocked yet");
}
$res_block = $limiter->check_access($ip_invalid, 'invalid6@example.com', false);
assert_true($res_block['blocked'] === true, 'Sixth invalid attempt must be blocked');
assert_equals(false, $res_block['send_email'], 'Blocked response should never send email');
assert_equals(4.0, $res_block['score'], 'Score after blocking invalid attempts should drop to 4 points');
assert_equals(480, $res_block['minutes'], 'Block duration should reflect 30 minutes per missing point');

// Test 4: Block duration helper should match recovery rate for various scores
$reflection = new ReflectionClass(FileBasedRateLimiter::class);
$method = $reflection->getMethod('calculate_block_minutes');
$method->setAccessible(true);

assert_equals(0, $method->invoke($limiter, 25), 'Score above threshold should not require wait time');
assert_equals(30, $method->invoke($limiter, 19), 'Deficit of 1 point should require 30 minutes');
assert_equals(900, $method->invoke($limiter, -10), 'Score well below threshold should scale wait linearly');
assert_equals(1, $method->invoke($limiter, 19.99), 'Minimal deficit should still return at least one minute');

echo "All rate limiter tests passed.\n";
exit(0);
