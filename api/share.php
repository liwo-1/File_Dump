<?php
/**
 * Share Link API
 *
 * Creates and deletes temporary public download links for files.
 * When you share a file, anyone with the link can download it without
 * needing the box password. Links expire after a set time.
 *
 * Actions:
 *   POST create — Generate a new share link for a file
 *   POST delete — Remove an existing share link
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();
initSession();

header('Content-Type: application/json');

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $status = 400): never {
    jsonResponse(['success' => false, 'error' => $message], $status);
}

// Must be logged into a box
if (!isBoxLoggedIn()) {
    jsonError('Not authenticated.', 403);
}

// CSRF check — accept from header or POST body
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $bodyToken = $_POST['csrf_token'] ?? '';
    $token = $headerToken ?: $bodyToken;
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonError('Invalid CSRF token.', 403);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();
$boxId = getCurrentBoxId();

// ==========================================================================
// GET: list — Get existing share links for a file
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $fileId = (int)($_GET['file_id'] ?? 0);

    if ($fileId <= 0) {
        jsonError('Invalid file ID.');
    }

    // Verify the file belongs to this box
    $stmt = $db->prepare('SELECT id FROM files WHERE id = ? AND box_id = ?');
    $stmt->execute([$fileId, $boxId]);
    if (!$stmt->fetch()) {
        jsonError('File not found.', 404);
    }

    $stmt = $db->prepare(
        'SELECT id, token, expires_at, created_at FROM shared_links
         WHERE file_id = ? ORDER BY created_at DESC'
    );
    $stmt->execute([$fileId]);
    $links = $stmt->fetchAll();

    // Build URLs and mark expired
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    foreach ($links as &$link) {
        $link['url'] = "https://{$host}/share.php?token={$link['token']}";
        $link['expired'] = strtotime($link['expires_at']) < time();
        unset($link['token']); // Don't expose full token in list
    }

    jsonResponse(['success' => true, 'links' => $links]);
}

// All remaining actions require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

// ==========================================================================
// POST: create — Generate a share link for a file
// ==========================================================================
if ($action === 'create') {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $ttl = (int)($_POST['ttl'] ?? 86400); // Default: 24 hours

    if ($fileId <= 0) {
        jsonError('Invalid file ID.');
    }

    // Validate TTL (min 5 minutes, max 7 days)
    if ($ttl < 300 || $ttl > 604800) {
        jsonError('Expiry must be between 5 minutes and 7 days.');
    }

    // Verify the file belongs to this box
    $stmt = $db->prepare('SELECT id, original_name FROM files WHERE id = ? AND box_id = ?');
    $stmt->execute([$fileId, $boxId]);
    $file = $stmt->fetch();

    if (!$file) {
        jsonError('File not found.', 404);
    }

    // Generate a secure token and calculate expiry
    $token = generateShareToken();
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $stmt = $db->prepare(
        'INSERT INTO shared_links (file_id, token, expires_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$fileId, $token, $expiresAt]);

    // Build the share URL. Since SSL is terminated at the reverse proxy
    // (Nginx Proxy Manager), PHP always sees HTTP. We check the
    // X-Forwarded-Proto header that NPM sets, falling back to HTTPS
    // since the site is always served over SSL publicly.
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $scheme = ($forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $shareUrl = "{$scheme}://{$host}/share.php?token={$token}";

    $boxName = getCurrentBoxName();
    logActivity('share_create', $boxName, $file['original_name']);

    jsonResponse([
        'success'    => true,
        'share_url'  => $shareUrl,
        'token'      => $token,
        'expires_at' => $expiresAt,
        'file_name'  => $file['original_name'],
    ]);
}

// ==========================================================================
// POST: delete — Remove a share link
// ==========================================================================
if ($action === 'delete') {
    $linkId = (int)($_POST['link_id'] ?? 0);

    if ($linkId <= 0) {
        jsonError('Invalid link ID.');
    }

    // Verify the link belongs to a file in this box
    $stmt = $db->prepare(
        'SELECT sl.id FROM shared_links sl
         JOIN files f ON sl.file_id = f.id
         WHERE sl.id = ? AND f.box_id = ?'
    );
    $stmt->execute([$linkId, $boxId]);

    if (!$stmt->fetch()) {
        jsonError('Share link not found.', 404);
    }

    $stmt = $db->prepare('DELETE FROM shared_links WHERE id = ?');
    $stmt->execute([$linkId]);

    $boxName = getCurrentBoxName();
    logActivity('share_delete', $boxName);

    jsonResponse(['success' => true]);
}

jsonError('Unknown action: ' . $action);
