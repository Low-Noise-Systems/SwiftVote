<?php
declare(strict_types=1);

/**
 * Admin authentication guard for init endpoints
 * Requires admin authentication and redirects to JSON error if denied
 */

// Load security functions and config
require_once __DIR__ . '/../../../php-survey-backend/includes/security.php';
$config = require_once __DIR__ . '/../../../php-survey-backend/includes/config_loader.php';

// Use the centralized admin authentication
require_admin_auth($config);
