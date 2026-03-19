<?php
/**
 * Helper Functions
 *
 * Small utility functions used across the app:
 * - CSRF protection (prevents forged form submissions)
 * - Output escaping (prevents XSS attacks)
 * - File size formatting
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Generate a CSRF token and store it in the session.
 *
 * CSRF = Cross-Site Request Forgery. Here's the attack it prevents:
 * 1. You're logged into FileDump
 * 2. You visit a malicious website
 * 3. That site has a hidden form that submits to FileDump (e.g. deleting files)
 * 4. Your browser sends your session cookie along, so FileDump thinks it's you
 *
 * The fix: every form includes a random token that only our server knows.
 * When the form is submitted, we verify the token matches. A malicious site
 * can't guess this token, so their forged requests get rejected.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        // random_bytes(32) generates 32 bytes of cryptographically secure randomness.
        // bin2hex() converts it to a readable hex string (64 characters).
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden CSRF input field for use in HTML forms.
 * Usage in a form: <?= csrfField() ?>
 */
function csrfField(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Validate that a submitted CSRF token matches the one in the session.
 * Call this at the top of any POST handler.
 */
function validateCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Escape output for safe HTML display.
 *
 * This prevents XSS (Cross-Site Scripting) attacks. If a user uploads a
 * file named '<script>alert("hacked")</script>.txt', without escaping,
 * that script would execute in every visitor's browser. htmlspecialchars()
 * converts < > " & to their HTML entity equivalents so they display as
 * text instead of being interpreted as HTML/JS.
 *
 * Short name "e()" because we'll use it constantly in templates.
 */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Format a file size in bytes to a human-readable string.
 * e.g. 1536 => "1.50 KB", 1048576 => "1.00 MB"
 */
function formatFileSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float) $bytes;

    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }

    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Generate a UUID v4 for stored file names.
 *
 * We don't use the original filename on disk because:
 * 1. Two users might upload files with the same name
 * 2. Filenames can contain characters that cause issues on different OS's
 * 3. Predictable filenames are a security risk
 *
 * Instead we generate a random UUID and keep the original name in the database.
 */
function generateUuid(): string {
    $data = random_bytes(16);
    // Set version to 4 (random) and variant bits per RFC 4122
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate a secure token for shared download links.
 *
 * Unlike UUIDs (which are for file storage), share tokens need to be
 * long and unpredictable since they grant access without a password.
 * 32 random bytes = 64 hex characters = 256 bits of entropy.
 */
function generateShareToken(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Check if a box has enough quota for a file of the given size.
 * Returns an array with 'allowed' (bool), 'quota', 'used', and 'remaining'.
 * If quota is NULL (unlimited), always returns allowed = true.
 */
function checkBoxQuota(int $boxId, int $fileSize = 0): array {
    $db = getDB();

    $stmt = $db->prepare('SELECT quota FROM boxes WHERE id = ?');
    $stmt->execute([$boxId]);
    $quota = $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COALESCE(SUM(size), 0) FROM files WHERE box_id = ?');
    $stmt->execute([$boxId]);
    $used = (int)$stmt->fetchColumn();

    if ($quota === null || $quota === false) {
        return ['allowed' => true, 'quota' => null, 'used' => $used, 'remaining' => null];
    }

    $quota = (int)$quota;
    $remaining = $quota - $used;

    return [
        'allowed'   => ($used + $fileSize) <= $quota,
        'quota'     => $quota,
        'used'      => $used,
        'remaining' => max(0, $remaining),
    ];
}

/**
 * Log an activity event.
 *
 * @param string      $action   What happened: 'upload', 'download', 'delete', 'login', 'login_failed'
 * @param string|null $boxName  Which box (null for admin actions)
 * @param string|null $fileName File involved (null for login events)
 * @param int|null    $fileSize File size in bytes (null if not applicable)
 */
function logActivity(string $action, ?string $boxName = null, ?string $fileName = null, ?int $fileSize = null): void {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $db->prepare(
        'INSERT INTO activity_log (action, box_name, file_name, file_size, ip_address) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$action, $boxName, $fileName, $fileSize, $ip]);
}

/**
 * Redirect to a URL and stop execution.
 */
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

/**
 * Set a flash message that will be displayed once on the next page load.
 *
 * "Flash" messages are a common pattern: after a form submission, you
 * redirect the user and want to show a success/error message on the
 * next page. We store it in the session, display it once, then delete it.
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Get and clear the flash message (returns null if none).
 */
function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
