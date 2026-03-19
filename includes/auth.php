<?php
/**
 * Authentication & Session Management
 *
 * Handles logging in/out for both box users and admins.
 * Uses PHP sessions — when a user logs in, we store their info
 * in $_SESSION, which persists across page loads via a cookie.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Start the session with secure settings.
 *
 * Sessions work like this:
 * 1. PHP generates a random ID (e.g. "abc123")
 * 2. It sends this ID to the browser as a cookie
 * 3. On each request, the browser sends the cookie back
 * 4. PHP uses the ID to look up the user's data on the server
 *
 * The settings below make this process more secure.
 */
function initSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return; // Already started
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,   // JavaScript can't read the cookie (prevents XSS theft)
        'samesite' => 'Lax', // Cookie only sent from same site (prevents CSRF)
    ]);
    session_start();
}

/**
 * Log in to a box.
 * Returns true if the password matches, false otherwise.
 */
function loginBox(string $boxName, string $password): bool {
    $db = getDB();

    // Check rate limiting first
    if (isRateLimited()) {
        return false;
    }

    $stmt = $db->prepare('SELECT id, name, display_name, password_hash FROM boxes WHERE name = ? AND is_active = 1');
    $stmt->execute([$boxName]);
    $box = $stmt->fetch();

    if (!$box || !password_verify($password, $box['password_hash'])) {
        recordFailedAttempt();
        logActivity('login_failed', $boxName);
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['box_id'] = $box['id'];
    $_SESSION['box_name'] = $box['name'];
    $_SESSION['box_display_name'] = $box['display_name'];
    $_SESSION['box_logged_in'] = true;

    logActivity('login', $box['name']);

    return true;
}

/**
 * Log in as admin.
 */
function loginAdmin(string $username, string $password): bool {
    $db = getDB();

    if (isRateLimited()) {
        return false;
    }

    $stmt = $db->prepare('SELECT id, username, password_hash FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        recordFailedAttempt();
        logActivity('login_failed', null, $username);
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_logged_in'] = true;

    logActivity('admin_login');

    return true;
}

/**
 * Check if the current user is logged into a box.
 */
function isBoxLoggedIn(): bool {
    return !empty($_SESSION['box_logged_in']);
}

/**
 * Check if the current user is logged in as admin.
 */
function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

/**
 * Get the currently logged-in box ID.
 */
function getCurrentBoxId(): ?int {
    return $_SESSION['box_id'] ?? null;
}

/**
 * Get the currently logged-in box name.
 */
function getCurrentBoxName(): ?string {
    return $_SESSION['box_name'] ?? null;
}

/**
 * Log out (destroys the entire session).
 */
function logout(): void {
    $_SESSION = [];

    // Delete the session cookie from the browser
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path']);
    }

    session_destroy();
}

/**
 * Rate limiting — prevent brute-force password guessing.
 *
 * Uses progressive delays: the more you fail, the longer you wait.
 * - 1-5 failures:   no delay (normal typos)
 * - 6-10 failures:  2 second delay per attempt
 * - 11-20 failures: 5 second delay per attempt
 * - 21+ failures:   full lockout for 15 minutes
 *
 * This makes automated password guessing impractical while being
 * forgiving of normal users who mistype their password a few times.
 */
function isRateLimited(): bool {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Count recent failures in the last 15 minutes
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND attempted_at > datetime("now", "-15 minutes")'
    );
    $stmt->execute([$ip]);
    $attempts = (int)$stmt->fetchColumn();

    // Hard lockout after 20 attempts
    if ($attempts >= 20) {
        return true;
    }

    // Progressive delay: enforce a minimum gap between attempts
    if ($attempts >= 6) {
        $delaySec = ($attempts >= 11) ? 5 : 2;

        $stmt = $db->prepare(
            'SELECT attempted_at FROM login_attempts
             WHERE ip_address = ? ORDER BY attempted_at DESC LIMIT 1'
        );
        $stmt->execute([$ip]);
        $lastAttempt = $stmt->fetchColumn();

        if ($lastAttempt) {
            $elapsed = time() - strtotime($lastAttempt);
            if ($elapsed < $delaySec) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Record a failed login attempt.
 */
function recordFailedAttempt(): void {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $stmt = $db->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)');
    $stmt->execute([$ip]);

    // Clean up old attempts (older than 1 hour) to keep the table small
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 hour')");
}
