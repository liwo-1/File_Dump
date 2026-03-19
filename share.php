<?php
/**
 * Public Share Download
 *
 * This page allows anyone with a valid share token to download a file
 * without needing a box password. The token is checked against the
 * shared_links table, and if valid and not expired, the file is streamed.
 *
 * No session or login required — the token IS the authentication.
 */

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();

$token = $_GET['token'] ?? '';

// Validate token format (must be 64 hex characters)
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    showShareError('Invalid share link.');
    exit;
}

$db = getDB();

// Look up the share link and join with files + boxes to get all the info
// we need in one query: the file details and the box name (for the file path).
$stmt = $db->prepare(
    'SELECT sl.*, f.original_name, f.stored_name, f.size, f.mime_type,
            b.name as box_name
     FROM shared_links sl
     JOIN files f ON sl.file_id = f.id
     JOIN boxes b ON f.box_id = b.id
     WHERE sl.token = ?'
);
$stmt->execute([$token]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    showShareError('Share link not found or has been removed.');
    exit;
}

// Check if the link has expired
if (strtotime($link['expires_at']) < time()) {
    http_response_code(410); // 410 Gone
    showShareError('This share link has expired.');
    exit;
}

// Verify the file still exists on disk
$filePath = UPLOAD_DIR . '/' . $link['box_name'] . '/' . $link['stored_name'];
if (!file_exists($filePath)) {
    http_response_code(404);
    showShareError('The shared file is no longer available.');
    exit;
}

// If ?download is set, stream the file. Otherwise show the download page.
if (isset($_GET['download'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $link['original_name'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');

    if (ob_get_level()) {
        ob_end_clean();
    }

    readfile($filePath);
    exit;
}

// Show the download page
$fileName = e($link['original_name']);
$fileSize = formatFileSize($link['size']);
$expiresAt = e($link['expires_at']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> — Shared File</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1><?= e(APP_NAME) ?></h1>
        </header>

        <div class="share-page">
            <div class="share-file-info">
                <h2>Shared File</h2>
                <table class="file-table">
                    <tr>
                        <th>Name</th>
                        <td><?= $fileName ?></td>
                    </tr>
                    <tr>
                        <th>Size</th>
                        <td><?= $fileSize ?></td>
                    </tr>
                    <tr>
                        <th>Expires</th>
                        <td><?= $expiresAt ?></td>
                    </tr>
                </table>
                <a href="?token=<?= e($token) ?>&download"
                   class="btn btn-primary btn-large">Download File</a>
            </div>
        </div>
    </div>
    <script src="assets/theme.js"></script>
</body>
</html>
<?php

/**
 * Show a simple error page for share link issues.
 */
function showShareError(string $message): void {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e(APP_NAME) ?> — Share Link</title>
        <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
        <div class="container">
            <header>
                <h1><?= e(APP_NAME) ?></h1>
            </header>
            <div class="alert alert-error"><?= e($message) ?></div>
            <p><a href="/">Go to <?= e(APP_NAME) ?></a></p>
        </div>
        <script src="assets/theme.js"></script>
    </body>
    </html>
    <?php
}
