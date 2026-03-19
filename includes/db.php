<?php
/**
 * Database Connection & Setup
 *
 * Uses PDO (PHP Data Objects) to talk to SQLite. PDO is PHP's standard
 * way to work with databases — it works the same whether you use SQLite,
 * MySQL, or PostgreSQL, so if you ever want to switch databases later,
 * most of this code stays the same.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Get the database connection (singleton pattern).
 *
 * "Singleton" means we only create ONE connection and reuse it.
 * Opening a new database connection is slow, so we store it in a
 * static variable and return the same one every time.
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            // ERRMODE_EXCEPTION: if something goes wrong, PHP throws an error
            // instead of silently failing. This helps us catch bugs early.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // FETCH_ASSOC: when we read data, return it as key=>value arrays
            // e.g. ['name' => 'main', 'password_hash' => '...']
            // instead of numeric indexes like [0 => 'main', 1 => '...']
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enable WAL mode for better performance with concurrent reads/writes.
        // WAL = Write-Ahead Logging. Without it, SQLite locks the entire
        // database during writes, blocking all readers. With WAL, readers
        // and writers can work simultaneously.
        $pdo->exec('PRAGMA journal_mode=WAL');

        // Enable foreign keys. SQLite has them but they're OFF by default.
        // This ensures that if we delete a box, its files get deleted too
        // (because of ON DELETE CASCADE in the schema).
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    return $pdo;
}

/**
 * Initialize the database schema.
 *
 * Creates all tables if they don't exist yet. Safe to call multiple times —
 * "IF NOT EXISTS" means it skips tables that are already there.
 */
function initDatabase(): void {
    $db = getDB();

    $db->exec('
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS boxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            display_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active INTEGER DEFAULT 1,
            default_ttl INTEGER DEFAULT NULL
        )
    ');

    // Add default_ttl column to existing boxes tables (safe to run multiple times)
    try {
        $db->exec('ALTER TABLE boxes ADD COLUMN default_ttl INTEGER DEFAULT NULL');
    } catch (\PDOException $e) {
        // Column already exists — ignore
    }

    // Add totp_secret column — stores 2FA secret, NULL means 2FA not set up yet
    try {
        $db->exec('ALTER TABLE admins ADD COLUMN totp_secret TEXT DEFAULT NULL');
    } catch (\PDOException $e) {
        // Column already exists — ignore
    }

    // Add quota column — max storage in bytes, NULL means unlimited
    try {
        $db->exec('ALTER TABLE boxes ADD COLUMN quota INTEGER DEFAULT NULL');
    } catch (\PDOException $e) {
        // Column already exists — ignore
    }

    $db->exec('
        CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            box_id INTEGER NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            size INTEGER NOT NULL,
            mime_type TEXT,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE CASCADE
        )
    ');

    // Add expires_at column to existing files tables (safe to run multiple times)
    try {
        $db->exec('ALTER TABLE files ADD COLUMN expires_at DATETIME DEFAULT NULL');
    } catch (\PDOException $e) {
        // Column already exists — ignore
    }

    // Shared links — temporary public download links for files.
    // Anyone with the token can download the file without a box password.
    // Links auto-expire based on expires_at (cleaned up by cron).
    $db->exec('
        CREATE TABLE IF NOT EXISTS shared_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER NOT NULL,
            token TEXT UNIQUE NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
        )
    ');

    // Activity log — tracks uploads, downloads, deletions, logins
    $db->exec('
        CREATE TABLE IF NOT EXISTS activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            box_name TEXT,
            file_name TEXT,
            file_size INTEGER,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Track failed login attempts for rate limiting.
    // This prevents attackers from trying thousands of passwords quickly.
    $db->exec('
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

/**
 * Seed the database with initial data (first admin + main box).
 *
 * Only inserts if no admin user exists yet, so it's safe to call
 * multiple times without creating duplicates.
 */
function seedDatabase(): void {
    $db = getDB();

    // Check if any admin exists
    $count = $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($count == 0) {
        $stmt = $db->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
        $stmt->execute([
            SETUP_ADMIN_USER,
            password_hash(SETUP_ADMIN_PASS, PASSWORD_BCRYPT)
        ]);
    }

    // Check if main box exists
    $count = $db->query("SELECT COUNT(*) FROM boxes WHERE name = 'main'")->fetchColumn();
    if ($count == 0) {
        $stmt = $db->prepare('INSERT INTO boxes (name, display_name, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([
            DEFAULT_BOX,
            'Main',
            password_hash(SETUP_BOX_PASS, PASSWORD_BCRYPT)
        ]);

        // Create the upload directory for the main box
        $boxDir = UPLOAD_DIR . '/main';
        if (!is_dir($boxDir)) {
            mkdir($boxDir, 0755, true);
        }
    }
}
