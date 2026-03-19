<?php
/**
 * File Download Handler
 *
 * Streams a file to the browser with proper headers. Files are NEVER
 * served directly by Apache — they always go through this script so
 * we can check authentication first.
 *
 * Why stream through PHP instead of direct links?
 * - We can check if the user is logged in before serving the file
 * - Files are stored with UUID names, but we serve them with original names
 * - The upload directory is blocked by .htaccess anyway
 *
 * We use readfile() which streams the file in chunks automatically,
 * so even large files won't eat up all the server's memory.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();
initSession();

// Must be logged into a box
if (!isBoxLoggedIn()) {
    http_response_code(403);
    exit('Not authorized');
}

$fileId = (int)($_GET['id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(400);
    exit('Invalid file ID');
}

$db = getDB();
$boxId = getCurrentBoxId();
$boxName = getCurrentBoxName();

// Fetch file record — note we also check box_id to ensure users
// can only download files from their own box
$stmt = $db->prepare('SELECT * FROM files WHERE id = ? AND box_id = ?');
$stmt->execute([$fileId, $boxId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('File not found');
}

$filePath = UPLOAD_DIR . '/' . $boxName . '/' . $file['stored_name'];

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File not found on disk');
}

// Preview mode: serve with real MIME type and inline disposition so the
// browser displays the file (images, text) instead of downloading it.
// Only allow safe MIME types for preview to prevent XSS via HTML files.
$preview = isset($_GET['preview']);
$safeMimeTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'text/plain', 'text/csv', 'text/xml',
    'application/pdf', 'application/json',
];

// For text-like files that might have a wrong MIME type, detect by extension
$textExts = ['txt', 'md', 'csv', 'log', 'json', 'xml', 'css', 'js', 'sh', 'py'];
$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
if ($preview && in_array($ext, $textExts) && !in_array($file['mime_type'], $safeMimeTypes)) {
    $file['mime_type'] = 'text/plain';
}

if ($preview && in_array($file['mime_type'], $safeMimeTypes)) {
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
}

header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');

// Log the download (but not preview requests — those are just thumbnails/previews)
if (!$preview) {
    logActivity('download', $boxName, $file['original_name'], (int)$file['size']);
}

// Flush output buffers to prevent memory issues with large files.
// Without this, PHP might try to buffer the entire file in memory.
if (ob_get_level()) {
    ob_end_clean();
}

readfile($filePath);
exit;
