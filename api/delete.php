<?php
/**
 * File Delete Handler
 *
 * Deletes a file from both disk and database.
 * Protected by CSRF token and box authentication.
 *
 * Delete is a POST-only action (never GET) because:
 * - GET requests can be triggered by links, images, even prefetch
 * - A malicious page could include <img src="delete.php?id=5"> and
 *   the browser would send the request with your cookies
 * - POST + CSRF token ensures the request came from our own form
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();
initSession();

if (!isBoxLoggedIn()) {
    redirect('../index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../box.php');
}

if (!validateCsrf()) {
    setFlash('error', 'Invalid form submission.');
    redirect('../box.php');
}

$fileId = (int)($_POST['file_id'] ?? 0);
if ($fileId <= 0) {
    setFlash('error', 'Invalid file.');
    redirect('../box.php');
}

$db = getDB();
$boxId = getCurrentBoxId();
$boxName = getCurrentBoxName();

// Verify file belongs to current box
$stmt = $db->prepare('SELECT * FROM files WHERE id = ? AND box_id = ?');
$stmt->execute([$fileId, $boxId]);
$file = $stmt->fetch();

if (!$file) {
    setFlash('error', 'File not found.');
    redirect('../box.php');
}

// Delete from disk
$filePath = UPLOAD_DIR . '/' . $boxName . '/' . $file['stored_name'];
if (file_exists($filePath)) {
    unlink($filePath);
}

// Delete from database
$stmt = $db->prepare('DELETE FROM files WHERE id = ?');
$stmt->execute([$fileId]);

logActivity('delete', $boxName, $file['original_name'], (int)$file['size']);

setFlash('success', 'File "' . $file['original_name'] . '" deleted.');
redirect('../box.php');
