<?php
/**
 * Application Configuration
 *
 * Central place for all app settings. Other files include this
 * so they can reference paths, limits, and options without hardcoding.
 */

// Prevent direct access to this file via URL
if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

// --- Paths ---
// __DIR__ is a PHP magic constant that gives us the directory of THIS file (includes/).
// dirname(__DIR__) goes one level up, which is our project root.
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', DATA_DIR . '/uploads');
define('DB_PATH', DATA_DIR . '/filedump.db');

// --- Upload Limits ---
// Max total file size in bytes (50GB). Individual chunks are much smaller.
define('MAX_UPLOAD_SIZE', 50 * 1024 * 1024 * 1024);

// --- Chunked Upload Settings ---
// Each chunk is 10MB. The browser slices files into pieces this size
// and uploads them one at a time. This keeps each HTTP request small
// so PHP's post_max_size doesn't need to be huge, and if a chunk fails
// only that piece needs to be re-sent (not the whole file).
define('CHUNK_SIZE', 50 * 1024 * 1024); // 50MB per chunk
define('CHUNK_DIR', DATA_DIR . '/chunks'); // Temp storage for in-progress uploads
define('CHUNK_MAX_AGE', 3600); // Stale chunks older than 1h get cleaned up

// --- Session Settings ---
// Sessions are how PHP remembers who is logged in between page loads.
// Each user gets a unique session ID stored in a browser cookie.
define('SESSION_LIFETIME', 43200); // 12 hours in seconds (box sessions)
define('ADMIN_SESSION_LIFETIME', 7200); // 2 hours in seconds (admin sessions)
define('SESSION_NAME', 'filedump_session');

// --- App Settings ---
define('APP_NAME', 'File Dump');
define('DEFAULT_BOX', 'main');

// --- Admin Setup ---
// These are only used by the setup script to create the first admin account.
// Change the password immediately after first login!
define('SETUP_ADMIN_USER', 'admin');
define('SETUP_ADMIN_PASS', 'changeme');
define('SETUP_BOX_PASS', 'changeme');
