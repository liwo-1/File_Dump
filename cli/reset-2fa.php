<?php
/**
 * Reset 2FA — CLI Script
 *
 * Run this from the command line to disable 2FA for an admin account.
 * Use this if you've lost access to your authenticator app.
 *
 * Usage:
 *   php /var/www/html/filedump/cli/reset-2fa.php
 *   php /var/www/html/filedump/cli/reset-2fa.php admin
 *
 * Without arguments, it lists all admin accounts.
 * With a username argument, it resets 2FA for that account.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';

initDatabase();
$db = getDB();

$username = $argv[1] ?? null;

if (!$username) {
    // List all admin accounts and their 2FA status
    echo "FileDump — 2FA Reset Tool\n";
    echo "========================\n\n";

    $admins = $db->query('SELECT username, totp_secret FROM admins ORDER BY username')->fetchAll();

    if (empty($admins)) {
        echo "No admin accounts found.\n";
        exit(1);
    }

    echo "Admin accounts:\n";
    foreach ($admins as $admin) {
        $status = $admin['totp_secret'] ? '2FA ENABLED' : 'No 2FA';
        echo "  {$admin['username']} — {$status}\n";
    }

    echo "\nUsage: php " . $argv[0] . " <username>\n";
    exit(0);
}

// Reset 2FA for the specified user
$stmt = $db->prepare('SELECT id, username, totp_secret FROM admins WHERE username = ?');
$stmt->execute([$username]);
$admin = $stmt->fetch();

if (!$admin) {
    echo "Error: Admin account '{$username}' not found.\n";
    exit(1);
}

if (empty($admin['totp_secret'])) {
    echo "Account '{$username}' does not have 2FA enabled.\n";
    exit(0);
}

$stmt = $db->prepare('UPDATE admins SET totp_secret = NULL WHERE id = ?');
$stmt->execute([$admin['id']]);

echo "2FA has been disabled for '{$username}'.\n";
echo "They will be prompted to set it up again on next login.\n";
