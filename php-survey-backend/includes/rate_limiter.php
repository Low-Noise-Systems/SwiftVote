<?php
/**
 * File-based IP reputation rate limiting
 * No database required - uses atomic file operations with proper locking
 *
 * Credit System:
 * - Each IP starts with 100 points
 * - Invalid email: -16 points (allows 5 attempts before blocking)
 * - Valid email: -10 points
 * - Cooldown violation: -5 points
 * Recovery: +2 point per hour of inactivity
 * - Blocked when score < 20
 * - Block duration: (20 - score) * 60 minutes (matches recovery rate)
 *
 * Cooldown System:
 * - 3 minutes cooldown per email address
 * - Separate cooldowns for 'voter' and 'admin' contexts
 * - Allows requesting admin link and voter link without cross-interference
 *
 * @author Rate Limiting Enhancement
 * @version 2.1
 */

class FileBasedRateLimiter {
    private $storage_dir;
    private $lock_timeout = 5; // seconds
    private $max_score = 100;
    private $block_threshold = 20;
    private $recovery_rate_per_hour = 2.0; // points recovered per hour
    private $cooldown_penalty = 5;

    /**
     * Constructor
     * @param string|null $storage_dir Path to storage directory (default: ../data/rate_limits)
     */
    public function __construct($storage_dir = null) {
        $this->storage_dir = $storage_dir ?: __DIR__ . '/../data/rate_limits';

        // Create directory if it doesn't exist
        if (!is_dir($this->storage_dir)) {
            if (!mkdir($this->storage_dir, 0755, true)) {
                error_log("Rate limit: Failed to create storage directory: {$this->storage_dir}");
            }
        }

        // Ensure directory is writable
        if (!is_writable($this->storage_dir)) {
            error_log("Rate limit: Storage directory is not writable: {$this->storage_dir}");
        }
    }

    /**
     * Check if IP has access and update reputation score
     *
     * @param string $ip Client IP address
     * @param string $email Email address being requested
     * @param bool $is_valid_email Whether the email is in the authorized list
     * @param string $context Context for cooldown tracking ('voter' or 'admin') - allows separate cooldowns per context
     * @return array ['blocked' => bool, 'send_email' => bool, 'reason' => string, 'score' => int, 'minutes' => int|null]
     */
    public function check_access($ip, $email, $is_valid_email, $context = 'voter') {
        $ip_file = $this->get_ip_file($ip);
        $lock_file = $ip_file . '.lock';

        // Acquire exclusive lock (prevents race conditions)
        $lock = fopen($lock_file, 'c');
        if (!$lock) {
            error_log("Rate limit: Could not create lock file for IP $ip");
            // Fail-safe: allow request but log error
            return ['blocked' => false, 'send_email' => $is_valid_email, 'reason' => 'lock_error', 'score' => 100];
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            error_log("Rate limit: Could not acquire lock for IP $ip");
            // Fail-safe: allow request but log error
            return ['blocked' => false, 'send_email' => $is_valid_email, 'reason' => 'lock_error', 'score' => 100];
        }

        try {
            // Load IP data (with lock held)
            $data = $this->load_ip_data($ip_file);

            // Recover points based on time elapsed (1 point per hour)
            $hours_elapsed = max(0, (time() - $data['last_request']) / 3600);
            if ($hours_elapsed > 0) {
                $data['score'] = min(
                    $this->max_score,
                    $data['score'] + ($hours_elapsed * $this->recovery_rate_per_hour)
                );
            }

            // Calculate the point cost for this request
            // Valid emails cost less to allow more legitimate users
            $point_cost = $is_valid_email ? 10 : 16;

            // Check if this request would bring score below 20 (block threshold)
            if ($data['score'] < 20 || ($data['score'] - $point_cost) < 20) {
                // Already blocked or would be blocked by this request
                $current_score = $data['score'];
                $block_minutes = $this->calculate_block_minutes($current_score);

                // If score is already below 20, use current score. Otherwise, it will be after this request
                if ($current_score >= 20) {
                    // This request would push score below 20
                    $data['score'] -= $point_cost;
                    $data['score'] = max(0, $data['score']);
                    if (!$is_valid_email) {
                        $data['invalid_count'] = ($data['invalid_count'] ?? 0) + 1;
                    } else {
                        $data['valid_count'] = ($data['valid_count'] ?? 0) + 1;
                    }
                    $block_minutes = $this->calculate_block_minutes($data['score']);
                }

                $result = [
                    'blocked' => true,
                    'minutes' => $block_minutes,
                    'score' => round($data['score'], 2),
                    'reason' => 'score_too_low',
                    'send_email' => false
                ];

                // Update last_request time even when blocked (for recovery tracking)
                $data['last_request'] = time();
                $this->save_ip_data($ip_file, $data);

                return $result;
            }

            // Cooldown check BEFORE deducting points (3 minutes cooldown per context)
            // Separate cooldown tracking for 'voter' and 'admin' contexts
            $email_hash = hash('sha256', strtolower(trim($email)));
            $context_key = $context === 'admin' ? 'admin' : 'voter';
            $last_hash_key = 'last_email_hash_' . $context_key;
            $last_time_key = 'last_email_time_' . $context_key;
            
            if (isset($data[$last_hash_key]) &&
                $data[$last_hash_key] === $email_hash &&
                (time() - $data[$last_time_key]) < 180) {

                $data['score'] -= $this->cooldown_penalty;  // Small penalty for spam/duplicate requests
                $data['score'] = max(0, $data['score']);
                $data['last_request'] = time();
                $data['cooldown_violations'] = ($data['cooldown_violations'] ?? 0) + 1;
                $this->save_ip_data($ip_file, $data);

                $cooldown_remaining = 180 - (time() - $data[$last_time_key]);
                return [
                    'blocked' => false,
                    'send_email' => false,
                    'reason' => 'cooldown',
                    'score' => round($data['score'], 2),
                    'cooldown_seconds' => $cooldown_remaining
                ];
            }

            // Deduct points based on action (using pre-calculated cost)
            $data['score'] -= $point_cost;
            $data['score'] = max(0, $data['score']);
            if (!$is_valid_email) {
                $data['invalid_count'] = ($data['invalid_count'] ?? 0) + 1;
            } else {
                $data['valid_count'] = ($data['valid_count'] ?? 0) + 1;
            }

            // Update tracking (context-specific for separate cooldowns)
            if ($is_valid_email) {
                $data[$last_hash_key] = $email_hash;
                $data[$last_time_key] = time();
            }
            $data['last_request'] = time();

            // Save updated data (still holding lock)
            $this->save_ip_data($ip_file, $data);

            return [
                'blocked' => false,
                'send_email' => $is_valid_email,
                'reason' => 'ok',
                'score' => round($data['score'], 2)
            ];

        } finally {
            // Always release lock
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Load IP data from file (private - call with lock held)
     * @param string $ip_file Path to IP data file
     * @return array IP data structure
     */
    private function load_ip_data($ip_file) {
        if (!file_exists($ip_file)) {
            return [
                'score' => 100,
                'last_request' => time(),
                'last_email_hash' => null,
                'last_email_time' => 0,
                'invalid_count' => 0,
                'valid_count' => 0,
                'cooldown_violations' => 0,
                'created' => time()
            ];
        }

        $json = file_get_contents($ip_file);
        if ($json === false) {
            error_log("Rate limit: Could not read file $ip_file");
            return $this->get_default_data();
        }

        $data = json_decode($json, true);

        // Handle corrupted files
        if (!is_array($data) || !isset($data['score'])) {
            error_log("Rate limit: Corrupted data file $ip_file, resetting");
            return $this->get_default_data();
        }

        return $data;
    }

    /**
     * Get default IP data structure
     * @return array Default data
     */
    private function get_default_data() {
        return [
            'score' => 100,
            'last_request' => time(),
            'last_email_hash' => null,
            'last_email_time' => 0,
            'invalid_count' => 0,
            'valid_count' => 0,
            'cooldown_violations' => 0,
            'created' => time()
        ];
    }

    /**
     * Save IP data to file (private - call with lock held)
     * Uses atomic write to prevent corruption
     * @param string $ip_file Path to IP data file
     * @param array $data IP data to save
     */
    private function save_ip_data($ip_file, $data) {
        $json = json_encode($data, JSON_PRETTY_PRINT);

        // Atomic write: write to temp file, then rename
        $temp_file = $ip_file . '.tmp.' . uniqid();
        if (file_put_contents($temp_file, $json, LOCK_EX) === false) {
            error_log("Rate limit: Could not write to temp file $temp_file");
            return;
        }

        if (!rename($temp_file, $ip_file)) {
            error_log("Rate limit: Could not rename temp file to $ip_file");
            if (file_exists($temp_file)) unlink($temp_file); // Clean up temp file
        }
    }

    /**
     * Get file path for IP (hashed for privacy)
     * @param string $ip IP address
     * @return string Path to IP data file
     */
    private function get_ip_file($ip) {
        $hash = hash('sha256', $ip);
        return $this->storage_dir . '/ip_' . $hash . '.json';
    }

    /**
     * Cleanup old IP files (run periodically via cron)
     * Removes files where:
     * - Last request was more than $days ago
     * - Score has recovered to 100 (no pending penalties)
     *
     * @param int $days Age threshold in days (default: 30)
     * @return int Number of files cleaned up
     */
    public function cleanup_old_files($days = 30) {
        $cutoff = time() - ($days * 86400);
        $cleaned = 0;

        $files = glob($this->storage_dir . '/ip_*.json');
        if ($files === false) {
            error_log("Rate limit: Could not list files in {$this->storage_dir}");
            return 0;
        }

        foreach ($files as $file) {
            // Skip lock and temp files
            if (strpos($file, '.lock') !== false || strpos($file, '.tmp') !== false) {
                continue;
            }

            $json = file_get_contents($file);
            if ($json === false) {
                continue;
            }

            $data = json_decode($json, true);

            // Delete if:
            // 1. Last request was > $days ago AND
            // 2. Score has recovered to 100 (no pending penalties)
            if (isset($data['last_request']) &&
                $data['last_request'] < $cutoff &&
                $data['score'] >= 100) {

                if (unlink($file)) {
                    $cleaned++;
                    // Also remove lock file if exists
                    $lock_file = $file . '.lock';
                    if (file_exists($lock_file)) {
                        if (!unlink($lock_file)) {
                            error_log("Rate limit: Failed to delete lock file $lock_file");
                        }
                    }
                } else {
                    error_log("Rate limit: Failed to remove data file $file during cleanup");
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get IP statistics (for debugging/monitoring)
     * @param string $ip IP address
     * @return array|null IP data or null if not found
     */
    public function get_ip_stats($ip) {
        $ip_file = $this->get_ip_file($ip);
        if (!file_exists($ip_file)) {
            return null;
        }

        $json = file_get_contents($ip_file);
        if ($json === false) {
            return null;
        }

        return json_decode($json, true);
    }

    /**
     * Get total number of tracked IPs
     * @return int Number of IP files
     */
    public function get_tracked_ip_count() {
        $files = glob($this->storage_dir . '/ip_*.json');
        if ($files === false) {
            return 0;
        }

        // Filter out lock and temp files
        $files = array_filter($files, function($file) {
            return strpos($file, '.lock') === false && strpos($file, '.tmp') === false;
        });

        return count($files);
    }

    /**
     * Manually reset an IP's reputation (for admin use)
     * @param string $ip IP address to reset
     * @return bool Success
     */
    public function reset_ip($ip) {
        $ip_file = $this->get_ip_file($ip);
        if (file_exists($ip_file)) {
            $lock_file = $ip_file . '.lock';
            if (file_exists($lock_file)) {
                if (!unlink($lock_file)) {
                    error_log("Rate limit: Failed to delete lock file $lock_file");
                }
            }
            return unlink($ip_file);
        }
        return true;
    }

    /**
     * Calculate the time (in minutes) an IP should wait based on its score deficit.
     * Keeps the message consistent with the recovery rate (1 point per hour).
     *
     * @param float $score Current score
     * @return int Minutes to wait
     */
    private function calculate_block_minutes($score) {
        $deficit = max(0, $this->block_threshold - $score);
        if ($deficit <= 0) {
            return 0;
        }
        $minutes = ceil(($deficit / $this->recovery_rate_per_hour) * 60);
        return (int) max(1, $minutes);
    }
}
