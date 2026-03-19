<?php
/**
 * Cleanup Cron Job
 *
 * Run this periodically (e.g. every 15 minutes) to:
 * 1. Delete expired files (files past their expires_at timestamp)
 * 2. Remove stale upload chunks (incomplete uploads older than CHUNK_MAX_AGE)
 *
 * Setup:
 *   crontab -e
 *   Every 15 min: php /var/www/html/filedump/cron/cleanup.php >> /var/log/filedump-cleanup.log 2>&1
 *
 * This script is designed to run from the command line (CLI), not from a browser.
 * The .htaccess rules should block web access to this directory anyway.
 */

// Ensure we're running from CLI, not web
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();

$db = getDB();
$now = date('Y-m-d H:i:s');

echo "[{$now}] Cleanup started\n";

// =========================================================================
// 1. Delete expired files
// =========================================================================
// Find files where expires_at is set and has passed.
$stmt = $db->prepare(
    'SELECT f.*, b.name as box_name FROM files f
     JOIN boxes b ON f.box_id = b.id
     WHERE f.expires_at IS NOT NULL AND f.expires_at <= ?'
);
$stmt->execute([$now]);
$expiredFiles = $stmt->fetchAll();

$expiredCount = 0;
foreach ($expiredFiles as $file) {
    // Delete the actual file from disk
    $filePath = UPLOAD_DIR . '/' . $file['box_name'] . '/' . $file['stored_name'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete the database record
    $delStmt = $db->prepare('DELETE FROM files WHERE id = ?');
    $delStmt->execute([$file['id']]);

    $expiredCount++;
    echo "  Expired: {$file['original_name']} (box: {$file['box_name']})\n";
}

echo "  Deleted {$expiredCount} expired file(s)\n";

// =========================================================================
// 2. Clean up stale upload chunks
// =========================================================================
// Check each directory in data/chunks/ for a meta.json file.
// If the upload was started more than CHUNK_MAX_AGE seconds ago, delete it.
$staleCount = 0;
$chunkBaseDir = CHUNK_DIR;

if (is_dir($chunkBaseDir)) {
    foreach (scandir($chunkBaseDir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;

        $uploadDir = $chunkBaseDir . '/' . $entry;
        if (!is_dir($uploadDir)) continue;

        $metaFile = $uploadDir . '/meta.json';
        $isStale = false;

        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            $age = time() - ($meta['created_at'] ?? 0);
            $isStale = ($age > CHUNK_MAX_AGE);
        } else {
            // No metadata file — orphaned directory, treat as stale
            $isStale = true;
        }

        if ($isStale) {
            // Delete all files in the chunk directory
            foreach (glob($uploadDir . '/*') as $f) {
                unlink($f);
            }
            rmdir($uploadDir);
            $staleCount++;
            echo "  Stale chunks: {$entry}\n";
        }
    }
}

echo "  Cleaned {$staleCount} stale upload(s)\n";

// =========================================================================
// 3. Delete expired shared links
// =========================================================================
$stmt = $db->prepare(
    'DELETE FROM shared_links WHERE expires_at IS NOT NULL AND expires_at <= ?'
);
$stmt->execute([$now]);
$expiredLinks = $stmt->rowCount();
echo "  Deleted {$expiredLinks} expired shared link(s)\n";

// =========================================================================
// 4. Prune old activity log entries (older than 30 days)
// =========================================================================
$stmt = $db->prepare(
    "DELETE FROM activity_log WHERE created_at < datetime('now', '-30 days')"
);
$stmt->execute();
$prunedLogs = $stmt->rowCount();
if ($prunedLogs > 0) {
    echo "  Pruned {$prunedLogs} old activity log entries\n";
}

// =========================================================================
// 5. Clean up old login attempts (older than 1 hour)
// =========================================================================
$db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 hour')");

echo "[{$now}] Cleanup complete\n\n";
