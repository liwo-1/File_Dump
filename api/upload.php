<?php
/**
 * File Upload Handler
 *
 * Receives a file from the upload form, validates it, generates a
 * UUID filename, stores it on disk, and records it in the database.
 *
 * The file goes through several checks before being saved:
 * 1. Is the user logged in?
 * 2. Was a file actually submitted?
 * 3. Did the upload complete without errors?
 * 4. Is the CSRF token valid?
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
    redirect('../index.php');
}

// Must be a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../box.php');
}

// Detect if PHP discarded the POST body because the file was too large.
// When Content-Length exceeds post_max_size, PHP empties $_POST and $_FILES
// entirely, which makes the CSRF check fail with a confusing message.
if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) &&
    $_SERVER['CONTENT_LENGTH'] > 0) {
    $maxSize = ini_get('post_max_size');
    setFlash('error', "File too large. The server limit is {$maxSize}. Ask your admin to increase post_max_size and upload_max_filesize in php.ini.");
    redirect('../box.php');
}

// CSRF check
if (!validateCsrf()) {
    setFlash('error', 'Invalid form submission.');
    redirect('../box.php');
}

// Check if a file was uploaded
if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    setFlash('error', 'No file selected.');
    redirect('../box.php');
}

$file = $_FILES['file'];

// Check for upload errors.
// PHP uses error codes to tell you what went wrong. UPLOAD_ERR_OK (0) means success.
// Common errors: UPLOAD_ERR_INI_SIZE (file exceeds php.ini limit),
// UPLOAD_ERR_PARTIAL (upload was interrupted).
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];
    $msg = $errorMessages[$file['error']] ?? 'Unknown upload error.';
    setFlash('error', $msg);
    redirect('../box.php');
}

$boxId = getCurrentBoxId();
$boxName = getCurrentBoxName();

// Check storage quota before accepting the file
$quotaCheck = checkBoxQuota($boxId, $file['size']);
if (!$quotaCheck['allowed']) {
    setFlash('error', 'Storage quota exceeded. This box has ' . formatFileSize($quotaCheck['remaining']) . ' remaining but the file is ' . formatFileSize($file['size']) . '.');
    redirect('../box.php');
}

// Generate a safe stored filename using UUID
// We keep the original extension so the OS can still associate file types
// basename() strips path tricks like ../../ but on Linux doesn't handle backslashes,
// so we normalize Windows-style paths first to block ..\..\windows\... attacks
$originalName = basename(str_replace('\\', '/', $file['name']));
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$storedName = generateUuid() . ($extension ? '.' . $extension : '');

// Ensure the box upload directory exists
$boxDir = UPLOAD_DIR . '/' . $boxName;
if (!is_dir($boxDir)) {
    mkdir($boxDir, 0755, true);
}

$destination = $boxDir . '/' . $storedName;

// move_uploaded_file() is the ONLY safe way to move uploaded files in PHP.
// It verifies the file actually came from an HTTP upload (not a forged path).
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    setFlash('error', 'Failed to save file.');
    redirect('../box.php');
}

// Calculate expiry: check for per-file TTL override, then box default
$ttl = isset($_POST['ttl']) && $_POST['ttl'] !== '' ? (int)$_POST['ttl'] : null;
if ($ttl === null) {
    $db = getDB();
    $stmt = $db->prepare('SELECT default_ttl FROM boxes WHERE id = ?');
    $stmt->execute([$boxId]);
    $boxTtl = $stmt->fetchColumn();
    if ($boxTtl) {
        $ttl = (int)$boxTtl;
    }
}
$expiresAt = $ttl ? date('Y-m-d H:i:s', time() + $ttl) : null;

// Record in database
$db = getDB();
$stmt = $db->prepare(
    'INSERT INTO files (box_id, original_name, stored_name, size, mime_type, expires_at)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $boxId,
    $originalName,
    $storedName,
    $file['size'],
    $file['type'] ?: 'application/octet-stream',
    $expiresAt,
]);

logActivity('upload', $boxName, $originalName, $file['size']);

setFlash('success', 'File "' . $originalName . '" uploaded successfully.');
redirect('../box.php');
