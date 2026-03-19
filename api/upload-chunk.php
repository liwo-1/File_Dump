<?php
/**
 * Chunked Upload Handler
 *
 * This endpoint receives file uploads in small pieces (chunks) instead of
 * one giant request. The JavaScript client (app.js) slices the file into
 * 10MB chunks and sends them here one at a time.
 *
 * It supports three actions:
 *
 * 1. "init"     — Start a new upload. Returns an upload ID.
 * 2. "chunk"    — Upload one chunk (identified by index).
 * 3. "complete" — All chunks sent. Server assembles them into the final file.
 *
 * There's also a GET endpoint:
 * 4. "status"   — Returns which chunks have been received (for resume).
 *
 * All responses are JSON since this is called by JavaScript, not by HTML forms.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();
initSession();

// All responses from this endpoint are JSON
header('Content-Type: application/json');

/**
 * Send a JSON response and exit.
 */
function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/**
 * Send a JSON error and exit.
 */
function jsonError(string $message, int $status = 400): never {
    jsonResponse(['success' => false, 'error' => $message], $status);
}

// --- Auth check ---
if (!isBoxLoggedIn()) {
    jsonError('Not authenticated.', 403);
}

// --- CSRF check for all POST requests ---
// The CSRF token is usually sent as an HTTP header (X-CSRF-Token) from
// JavaScript fetch() calls. For sendBeacon requests (tab close cleanup),
// headers can't be set, so we also accept it from the POST body.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $bodyToken = $_POST['csrf_token'] ?? '';
    $token = $headerToken ?: $bodyToken;
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonError('Invalid CSRF token.', 403);
    }
}

$boxId = getCurrentBoxId();
$boxName = getCurrentBoxName();

// Determine which action to perform
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==========================================================================
// GET: status — Check which chunks have been uploaded (for resume support)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    $uploadId = $_GET['upload_id'] ?? '';

    if (!preg_match('/^[a-f0-9\-]{36}$/', $uploadId)) {
        jsonError('Invalid upload ID.');
    }

    $chunkDir = CHUNK_DIR . '/' . $uploadId;
    $uploaded = [];

    if (is_dir($chunkDir)) {
        // Read the metadata file to verify this upload belongs to this box
        $metaFile = $chunkDir . '/meta.json';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if (($meta['box_id'] ?? 0) !== $boxId) {
                jsonError('Upload does not belong to this box.', 403);
            }
        }

        // List all chunk files and extract their indices
        foreach (glob($chunkDir . '/chunk_*') as $file) {
            $index = (int)str_replace('chunk_', '', basename($file));
            $uploaded[] = $index;
        }
        sort($uploaded);
    }

    jsonResponse(['success' => true, 'uploaded_chunks' => $uploaded]);
}

// All remaining actions require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

// ==========================================================================
// POST: init — Start a new chunked upload
// ==========================================================================
if ($action === 'init') {
    $fileName = $_POST['file_name'] ?? '';
    $fileSize = (int)($_POST['file_size'] ?? 0);
    $totalChunks = (int)($_POST['total_chunks'] ?? 0);
    $mimeType = $_POST['mime_type'] ?? 'application/octet-stream';
    $ttl = isset($_POST['ttl']) && $_POST['ttl'] !== '' ? (int)$_POST['ttl'] : null;

    // Validate inputs
    if (empty($fileName) || $fileSize <= 0 || $totalChunks <= 0) {
        jsonError('Missing required fields: file_name, file_size, total_chunks.');
    }

    if ($fileSize > MAX_UPLOAD_SIZE) {
        jsonError('File exceeds maximum size of ' . formatFileSize(MAX_UPLOAD_SIZE) . '.');
    }

    // Check storage quota
    $quotaCheck = checkBoxQuota($boxId, $fileSize);
    if (!$quotaCheck['allowed']) {
        jsonError('Storage quota exceeded. ' . formatFileSize($quotaCheck['remaining']) . ' remaining, but file is ' . formatFileSize($fileSize) . '.');
    }

    // Sanitize the file name
    $fileName = basename(str_replace('\\', '/', $fileName));

    // Generate a unique upload ID (UUID) to track this upload
    $uploadId = generateUuid();

    // Create the temp directory for this upload's chunks
    $chunkDir = CHUNK_DIR . '/' . $uploadId;
    if (!mkdir($chunkDir, 0755, true)) {
        jsonError('Failed to create upload directory.', 500);
    }

    // If no TTL provided, check the box's default TTL
    if ($ttl === null) {
        $db = getDB();
        $stmt = $db->prepare('SELECT default_ttl FROM boxes WHERE id = ?');
        $stmt->execute([$boxId]);
        $boxTtl = $stmt->fetchColumn();
        if ($boxTtl) {
            $ttl = (int)$boxTtl;
        }
    }

    // Save metadata so we can verify ownership and reassemble later
    $meta = [
        'upload_id'    => $uploadId,
        'box_id'       => $boxId,
        'box_name'     => $boxName,
        'file_name'    => $fileName,
        'file_size'    => $fileSize,
        'total_chunks' => $totalChunks,
        'mime_type'    => $mimeType,
        'ttl'          => $ttl,
        'created_at'   => time(),
    ];
    file_put_contents($chunkDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

    jsonResponse([
        'success'   => true,
        'upload_id' => $uploadId,
        'chunk_size' => CHUNK_SIZE,
    ]);
}

// ==========================================================================
// POST: chunk — Receive a single chunk
// ==========================================================================
if ($action === 'chunk') {
    $uploadId = $_POST['upload_id'] ?? '';
    $chunkIndex = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : -1;

    // Validate upload ID format (must be a UUID)
    if (!preg_match('/^[a-f0-9\-]{36}$/', $uploadId)) {
        jsonError('Invalid upload ID.');
    }

    $chunkDir = CHUNK_DIR . '/' . $uploadId;

    // Verify the upload exists and belongs to this box
    $metaFile = $chunkDir . '/meta.json';
    if (!file_exists($metaFile)) {
        jsonError('Upload not found. It may have expired.');
    }

    $meta = json_decode(file_get_contents($metaFile), true);
    if (($meta['box_id'] ?? 0) !== $boxId) {
        jsonError('Upload does not belong to this box.', 403);
    }

    // Validate chunk index
    if ($chunkIndex < 0 || $chunkIndex >= $meta['total_chunks']) {
        jsonError('Invalid chunk index.');
    }

    // Check that a file was actually uploaded with this request
    if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        jsonError('No chunk data received.');
    }

    // Save the chunk to disk
    $chunkPath = $chunkDir . '/chunk_' . str_pad($chunkIndex, 6, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
        jsonError('Failed to save chunk.', 500);
    }

    // Count how many chunks we've received so far
    $receivedChunks = count(glob($chunkDir . '/chunk_*'));

    jsonResponse([
        'success'         => true,
        'chunk_index'     => $chunkIndex,
        'received_chunks' => $receivedChunks,
        'total_chunks'    => $meta['total_chunks'],
    ]);
}

// ==========================================================================
// POST: complete — Assemble all chunks into the final file
// ==========================================================================
if ($action === 'complete') {
    $uploadId = $_POST['upload_id'] ?? '';

    if (!preg_match('/^[a-f0-9\-]{36}$/', $uploadId)) {
        jsonError('Invalid upload ID.');
    }

    $chunkDir = CHUNK_DIR . '/' . $uploadId;
    $metaFile = $chunkDir . '/meta.json';

    if (!file_exists($metaFile)) {
        jsonError('Upload not found.');
    }

    $meta = json_decode(file_get_contents($metaFile), true);
    if (($meta['box_id'] ?? 0) !== $boxId) {
        jsonError('Upload does not belong to this box.', 403);
    }

    // Verify all chunks are present
    $receivedChunks = count(glob($chunkDir . '/chunk_*'));
    if ($receivedChunks !== $meta['total_chunks']) {
        jsonError("Missing chunks: received {$receivedChunks} of {$meta['total_chunks']}.");
    }

    // Generate the final stored filename
    $originalName = $meta['file_name'];
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $storedName = generateUuid() . ($extension ? '.' . $extension : '');

    // Ensure the box upload directory exists
    $boxDir = UPLOAD_DIR . '/' . $meta['box_name'];
    if (!is_dir($boxDir)) {
        mkdir($boxDir, 0755, true);
    }

    $destination = $boxDir . '/' . $storedName;

    // Assemble: open the destination file and append each chunk in order.
    // We read/write in 8KB buffers to avoid loading entire chunks into memory.
    $out = fopen($destination, 'wb');
    if (!$out) {
        jsonError('Failed to create output file.', 500);
    }

    for ($i = 0; $i < $meta['total_chunks']; $i++) {
        $chunkPath = $chunkDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);

        if (!file_exists($chunkPath)) {
            fclose($out);
            unlink($destination);
            jsonError("Chunk {$i} is missing.");
        }

        $in = fopen($chunkPath, 'rb');
        if (!$in) {
            fclose($out);
            unlink($destination);
            jsonError("Failed to read chunk {$i}.", 500);
        }

        // Stream the chunk into the final file in small pieces
        while (!feof($in)) {
            $buffer = fread($in, 8192);
            if ($buffer === false) {
                break;
            }
            fwrite($out, $buffer);
        }
        fclose($in);
    }

    fclose($out);

    // Verify the assembled file size matches what was expected
    $actualSize = filesize($destination);
    if ($actualSize !== $meta['file_size']) {
        unlink($destination);
        jsonError("Size mismatch: expected {$meta['file_size']} bytes, got {$actualSize}.");
    }

    // Calculate expiry time from TTL (if set)
    $expiresAt = null;
    if (!empty($meta['ttl'])) {
        $expiresAt = date('Y-m-d H:i:s', time() + $meta['ttl']);
    }

    // Record in database
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO files (box_id, original_name, stored_name, size, mime_type, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $meta['box_id'],
        $originalName,
        $storedName,
        $meta['file_size'],
        $meta['mime_type'],
        $expiresAt,
    ]);
    $fileId = $db->lastInsertId();

    // Clean up chunk directory
    foreach (glob($chunkDir . '/*') as $f) {
        unlink($f);
    }
    rmdir($chunkDir);

    logActivity('upload', $meta['box_name'], $originalName, $meta['file_size']);

    jsonResponse([
        'success'       => true,
        'file_id'       => (int)$fileId,
        'original_name' => $originalName,
        'size'          => $meta['file_size'],
    ]);
}

// ==========================================================================
// POST: cancel — Delete all chunks for an upload (cleanup on cancel/tab close)
// ==========================================================================
if ($action === 'cancel') {
    $uploadId = $_POST['upload_id'] ?? '';

    if (!preg_match('/^[a-f0-9\-]{36}$/', $uploadId)) {
        jsonError('Invalid upload ID.');
    }

    $chunkDir = CHUNK_DIR . '/' . $uploadId;

    if (is_dir($chunkDir)) {
        // Verify ownership
        $metaFile = $chunkDir . '/meta.json';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if (($meta['box_id'] ?? 0) !== $boxId) {
                jsonError('Upload does not belong to this box.', 403);
            }
        }

        // Delete all files in the chunk directory
        foreach (glob($chunkDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($chunkDir);
    }

    jsonResponse(['success' => true]);
}

// If we got here, the action was not recognized
jsonError('Unknown action: ' . $action);
