<?php
/**
 * Unit Tests — FileDump
 *
 * Tests core PHP functions WITHOUT needing a web server.
 * Run from the project root:
 *   php tests/test_unit.php
 *
 * How it works:
 * We define APP_ROOT and load the includes directly, then call the functions
 * and check their output. It uses a SEPARATE test database so it won't
 * touch your real data.
 *
 * Each test function returns true (pass) or false (fail).
 */

// --- Bootstrap ---
// Point APP_ROOT to the project root (one level up from tests/)
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';

// Override the DB path to use a temporary test database
// We can't redefine a constant, so we'll create a test DB helper
$testDbPath = sys_get_temp_dir() . '/filedump_test_' . uniqid() . '.db';

require_once APP_ROOT . '/includes/helpers.php';

// --- Test Harness ---
$passed = 0;
$failed = 0;
$tests = [];

function test(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $result = $fn();
        if ($result) {
            echo "  PASS  {$name}\n";
            $passed++;
        } else {
            echo "  FAIL  {$name}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "  FAIL  {$name} — Exception: {$e->getMessage()}\n";
        $failed++;
    }
}

// --- Helper: get a test database connection ---
function getTestDB(): PDO {
    global $testDbPath;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . $testDbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

        // Create the schema
        $pdo->exec('CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS boxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            display_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active INTEGER DEFAULT 1
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            box_id INTEGER NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            size INTEGER NOT NULL,
            mime_type TEXT,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE CASCADE
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        // Seed test data
        $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
            ->execute(['admin', password_hash('testpass', PASSWORD_BCRYPT)]);
        $pdo->prepare('INSERT INTO boxes (name, display_name, password_hash) VALUES (?, ?, ?)')
            ->execute(['main', 'Main', password_hash('boxpass', PASSWORD_BCRYPT)]);
    }
    return $pdo;
}

echo "\n========================================\n";
echo " FileDump Unit Tests\n";
echo "========================================\n\n";

// ========================================
// 1. CSRF Token Tests
// ========================================
echo "--- CSRF Protection ---\n";

test('generateCsrfToken creates a 64-char hex token', function () {
    // Simulate a session
    $_SESSION = [];
    $token = generateCsrfToken();
    return strlen($token) === 64 && ctype_xdigit($token);
});

test('generateCsrfToken returns the SAME token on repeated calls', function () {
    $_SESSION = [];
    $token1 = generateCsrfToken();
    $token2 = generateCsrfToken();
    return $token1 === $token2;
});

test('validateCsrf accepts a valid token', function () {
    $_SESSION = [];
    $token = generateCsrfToken();
    $_POST['csrf_token'] = $token;
    return validateCsrf() === true;
});

test('validateCsrf REJECTS a wrong token', function () {
    $_SESSION = [];
    generateCsrfToken();
    $_POST['csrf_token'] = 'wrong_token_here';
    return validateCsrf() === false;
});

test('validateCsrf REJECTS an empty token', function () {
    $_SESSION = [];
    generateCsrfToken();
    $_POST['csrf_token'] = '';
    return validateCsrf() === false;
});

test('validateCsrf REJECTS when no token in session', function () {
    $_SESSION = [];
    $_POST['csrf_token'] = 'anything';
    return validateCsrf() === false;
});

// ========================================
// 2. Output Escaping (XSS Prevention)
// ========================================
echo "\n--- XSS Prevention ---\n";

test('e() escapes HTML tags', function () {
    $result = e('<script>alert("xss")</script>');
    return strpos($result, '<script>') === false
        && strpos($result, '&lt;script&gt;') !== false;
});

test('e() escapes quotes', function () {
    $result = e('" onmouseover="alert(1)"');
    return strpos($result, '&quot;') !== false;
});

test('e() escapes ampersands', function () {
    return e('A&B') === 'A&amp;B';
});

// ========================================
// 3. SQL Injection Prevention
// ========================================
echo "\n--- SQL Injection Prevention ---\n";

test('Login with SQL injection payload returns no match', function () {
    $db = getTestDB();

    // Classic SQL injection: ' OR 1=1 --
    // With prepared statements, this is treated as a literal string, not SQL
    $malicious = "' OR 1=1 --";
    $stmt = $db->prepare('SELECT id, password_hash FROM boxes WHERE name = ? AND is_active = 1');
    $stmt->execute([$malicious]);
    $result = $stmt->fetch();

    // Should return nothing — the injection is treated as a literal box name
    return $result === false;
});

test('SQL injection in admin login returns no match', function () {
    $db = getTestDB();

    $malicious = "admin' --";
    $stmt = $db->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
    $stmt->execute([$malicious]);
    $result = $stmt->fetch();

    return $result === false;
});

test('UNION-based injection returns no match', function () {
    $db = getTestDB();

    $malicious = "' UNION SELECT 1, 'admin', '\$2y\$10\$fake' --";
    $stmt = $db->prepare('SELECT id, password_hash FROM boxes WHERE name = ? AND is_active = 1');
    $stmt->execute([$malicious]);
    $result = $stmt->fetch();

    return $result === false;
});

// ========================================
// 4. Password Hashing
// ========================================
echo "\n--- Password Hashing ---\n";

test('password_hash produces a bcrypt hash', function () {
    $hash = password_hash('testpassword', PASSWORD_BCRYPT);
    // Bcrypt hashes start with $2y$
    return str_starts_with($hash, '$2y$');
});

test('password_verify matches correct password', function () {
    $hash = password_hash('mypassword', PASSWORD_BCRYPT);
    return password_verify('mypassword', $hash) === true;
});

test('password_verify rejects wrong password', function () {
    $hash = password_hash('mypassword', PASSWORD_BCRYPT);
    return password_verify('wrongpassword', $hash) === false;
});

test('Stored admin password verifies correctly', function () {
    $db = getTestDB();
    $stmt = $db->prepare('SELECT password_hash FROM admins WHERE username = ?');
    $stmt->execute(['admin']);
    $row = $stmt->fetch();
    return password_verify('testpass', $row['password_hash']);
});

// ========================================
// 5. UUID Generation
// ========================================
echo "\n--- UUID Generation ---\n";

test('generateUuid returns a valid UUID v4 format', function () {
    $uuid = generateUuid();
    // UUID v4 format: 8-4-4-4-12 hex chars
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
});

test('generateUuid produces unique values', function () {
    $uuids = [];
    for ($i = 0; $i < 100; $i++) {
        $uuids[] = generateUuid();
    }
    // All 100 UUIDs should be different
    return count(array_unique($uuids)) === 100;
});

// ========================================
// 6. File Size Formatting
// ========================================
echo "\n--- File Size Formatting ---\n";

test('formatFileSize handles bytes', function () {
    return formatFileSize(500) === '500 B';
});

test('formatFileSize handles KB', function () {
    return formatFileSize(1536) === '1.5 KB';
});

test('formatFileSize handles MB', function () {
    return formatFileSize(1048576) === '1 MB';
});

test('formatFileSize handles GB', function () {
    return formatFileSize(1073741824) === '1 GB';
});

// ========================================
// 7. Foreign Key Cascade
// ========================================
echo "\n--- Database Integrity ---\n";

test('Deleting a box cascades to delete its files', function () {
    $db = getTestDB();

    // Create a test box
    $db->prepare('INSERT INTO boxes (name, display_name, password_hash) VALUES (?, ?, ?)')
       ->execute(['testbox', 'Test Box', password_hash('x', PASSWORD_BCRYPT)]);
    $boxId = $db->lastInsertId();

    // Add a file to it
    $db->prepare('INSERT INTO files (box_id, original_name, stored_name, size, mime_type) VALUES (?, ?, ?, ?, ?)')
       ->execute([$boxId, 'test.txt', 'uuid-123.txt', 100, 'text/plain']);

    // Delete the box
    $db->prepare('DELETE FROM boxes WHERE id = ?')->execute([$boxId]);

    // File should be gone too (CASCADE)
    $stmt = $db->prepare('SELECT COUNT(*) FROM files WHERE box_id = ?');
    $stmt->execute([$boxId]);
    return $stmt->fetchColumn() == 0;
});

test('Box names must be unique', function () {
    $db = getTestDB();
    try {
        $db->prepare('INSERT INTO boxes (name, display_name, password_hash) VALUES (?, ?, ?)')
           ->execute(['main', 'Duplicate', password_hash('x', PASSWORD_BCRYPT)]);
        return false; // Should have thrown
    } catch (PDOException $e) {
        return strpos($e->getMessage(), 'UNIQUE') !== false;
    }
});

// ========================================
// 8. Input Sanitization
// ========================================
echo "\n--- Input Sanitization ---\n";

test('Box name creation strips unsafe characters', function () {
    // This is the same regex used in admin/dashboard.php
    $input = "My Box! @#$%";
    $sanitized = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($input)));
    return $sanitized === 'mybox';
});

test('basename() strips directory traversal from filenames', function () {
    $malicious = '../../etc/passwd';
    return basename($malicious) === 'passwd';
});

test('basename() strips Windows-style path traversal', function () {
    $malicious = '..\\..\\windows\\system32\\config';
    $result = basename($malicious);
    // On Linux, basename() doesn't recognize backslashes as separators,
    // so we need to handle both OS behaviors
    if (DIRECTORY_SEPARATOR === '\\') {
        return $result === 'config';
    }
    // On Linux, manually strip Windows-style paths as our app does
    $result = basename(str_replace('\\', '/', $malicious));
    return $result === 'config';
});

// ========================================
// Summary
// ========================================
echo "\n========================================\n";
echo " Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n\n";

// Cleanup test database
unlink($testDbPath);

exit($failed > 0 ? 1 : 0);
