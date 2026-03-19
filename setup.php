<?php
/**
 * Setup Script — Run once to initialize the database and default data.
 *
 * Usage: Open this in your browser or run `php setup.php` from the command line.
 * After running, DELETE this file (or it becomes a security risk).
 */

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';

// Ensure data directories exist
$dirs = [DATA_DIR, UPLOAD_DIR, UPLOAD_DIR . '/main'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created directory: {$dir}\n";
    }
}

// Initialize database schema and seed data
initDatabase();
echo "Database tables created.\n";

seedDatabase();
echo "Default admin user and main box created.\n";

echo "\n--- Setup Complete ---\n";
echo "Admin login: " . SETUP_ADMIN_USER . " / " . SETUP_ADMIN_PASS . "\n";
echo "Main box password: " . SETUP_BOX_PASS . "\n";
echo "\nIMPORTANT: Change these passwords immediately after first login!\n";
echo "IMPORTANT: Delete this setup.php file after running it!\n";
