<?php
/**
 * Admin Dashboard
 *
 * Central management page for the admin. Handles:
 * - Listing all boxes with file counts
 * - Creating new boxes
 * - Deleting boxes (and all their files)
 * - Changing box passwords
 * - Viewing and deleting individual files in any box
 *
 * Each action is a POST form with CSRF protection.
 * The "action" field tells us which operation to perform.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/totp.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();
initSession();

if (!isAdminLoggedIn()) {
    redirect('index.php');
}

// Check admin session timeout (shorter than box sessions for security)
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > ADMIN_SESSION_LIFETIME) {
    logout();
    redirect('index.php');
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
    redirect('index.php');
}

$db = getDB();

// --- Process POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_box':
            $name = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['box_name'] ?? '')));
            $displayName = trim($_POST['display_name'] ?? '');
            $password = $_POST['box_password'] ?? '';

            if (empty($name) || empty($displayName) || empty($password)) {
                setFlash('error', 'All fields are required.');
            } else {
                // Check for duplicate
                $stmt = $db->prepare('SELECT COUNT(*) FROM boxes WHERE name = ?');
                $stmt->execute([$name]);
                if ($stmt->fetchColumn() > 0) {
                    setFlash('error', 'A box with that name already exists.');
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO boxes (name, display_name, password_hash) VALUES (?, ?, ?)'
                    );
                    $stmt->execute([$name, $displayName, password_hash($password, PASSWORD_BCRYPT)]);

                    // Create upload directory
                    $boxDir = UPLOAD_DIR . '/' . $name;
                    if (!is_dir($boxDir)) {
                        mkdir($boxDir, 0755, true);
                    }

                    logActivity('box_create', $name);
                    setFlash('success', 'Box "' . $displayName . '" created.');
                }
            }
            break;

        case 'delete_box':
            $boxId = (int)($_POST['box_id'] ?? 0);

            $stmt = $db->prepare('SELECT * FROM boxes WHERE id = ?');
            $stmt->execute([$boxId]);
            $box = $stmt->fetch();

            if ($box) {
                // Delete all files on disk
                $boxDir = UPLOAD_DIR . '/' . $box['name'];
                if (is_dir($boxDir)) {
                    // Delete each file in the directory
                    $dirFiles = glob($boxDir . '/*');
                    foreach ($dirFiles as $f) {
                        if (is_file($f)) {
                            unlink($f);
                        }
                    }
                    rmdir($boxDir);
                }

                // Delete from DB (CASCADE deletes file records too)
                $stmt = $db->prepare('DELETE FROM boxes WHERE id = ?');
                $stmt->execute([$boxId]);

                logActivity('box_delete', $box['name']);
                setFlash('success', 'Box "' . $box['display_name'] . '" deleted.');
            }
            break;

        case 'change_password':
            $boxId = (int)($_POST['box_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';

            if (empty($newPassword)) {
                setFlash('error', 'Password cannot be empty.');
            } else {
                $stmt = $db->prepare('UPDATE boxes SET password_hash = ? WHERE id = ?');
                $stmt->execute([password_hash($newPassword, PASSWORD_BCRYPT), $boxId]);

                $stmt = $db->prepare('SELECT name FROM boxes WHERE id = ?');
                $stmt->execute([$boxId]);
                $changedBox = $stmt->fetchColumn();
                logActivity('password_change', $changedBox);

                setFlash('success', 'Password updated.');
            }
            break;

        case 'set_quota':
            $boxId = (int)($_POST['box_id'] ?? 0);
            $quotaValue = $_POST['quota'] ?? '';
            $quota = ($quotaValue === '') ? null : (int)$quotaValue;

            $stmt = $db->prepare('UPDATE boxes SET quota = ? WHERE id = ?');
            $stmt->execute([$quota, $boxId]);
            setFlash('success', 'Storage quota updated.');
            break;

        case 'set_ttl':
            $boxId = (int)($_POST['box_id'] ?? 0);
            $ttlValue = $_POST['default_ttl'] ?? '';
            // Empty string = no expiry (NULL), otherwise seconds
            $ttl = ($ttlValue === '') ? null : (int)$ttlValue;

            $stmt = $db->prepare('UPDATE boxes SET default_ttl = ? WHERE id = ?');
            $stmt->execute([$ttl, $boxId]);
            setFlash('success', 'Default expiry updated.');
            break;

        case 'bulk_delete':
            $fileIds = $_POST['file_ids'] ?? [];
            if (!empty($fileIds) && is_array($fileIds)) {
                $deleted = 0;
                foreach ($fileIds as $fid) {
                    $fid = (int)$fid;
                    $stmt = $db->prepare(
                        'SELECT f.*, b.name as box_name FROM files f
                         JOIN boxes b ON f.box_id = b.id WHERE f.id = ?'
                    );
                    $stmt->execute([$fid]);
                    $file = $stmt->fetch();

                    if ($file) {
                        $filePath = UPLOAD_DIR . '/' . $file['box_name'] . '/' . $file['stored_name'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                        $stmt = $db->prepare('DELETE FROM files WHERE id = ?');
                        $stmt->execute([$fid]);
                        $deleted++;
                    }
                }
                setFlash('success', "Deleted {$deleted} file(s).");
            } else {
                setFlash('error', 'No files selected.');
            }
            break;

        case 'delete_file':
            $fileId = (int)($_POST['file_id'] ?? 0);

            $stmt = $db->prepare(
                'SELECT f.*, b.name as box_name FROM files f
                 JOIN boxes b ON f.box_id = b.id WHERE f.id = ?'
            );
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();

            if ($file) {
                $filePath = UPLOAD_DIR . '/' . $file['box_name'] . '/' . $file['stored_name'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $stmt = $db->prepare('DELETE FROM files WHERE id = ?');
                $stmt->execute([$fileId]);
                setFlash('success', 'File "' . $file['original_name'] . '" deleted.');
            }
            break;

        case 'setup_2fa':
            // Generate a new TOTP secret and store it temporarily in session
            // It only gets saved to DB after the user verifies a code
            $_SESSION['pending_totp_secret'] = generateTotpSecret();
            // Don't redirect — we need to show the QR code on this page load
            break;

        case 'verify_2fa':
            $code = trim($_POST['totp_code'] ?? '');
            $pendingSecret = $_SESSION['pending_totp_secret'] ?? '';

            if (empty($pendingSecret)) {
                setFlash('error', '2FA setup expired. Please try again.');
            } elseif (verifyTotpCode($pendingSecret, $code)) {
                // Code verified — save the secret to the database
                $stmt = $db->prepare('UPDATE admins SET totp_secret = ? WHERE id = ?');
                $stmt->execute([$pendingSecret, $_SESSION['admin_id']]);
                unset($_SESSION['pending_totp_secret']);
                logActivity('2fa_enable');
                setFlash('success', 'Two-factor authentication enabled.');
            } else {
                setFlash('error', 'Invalid code. Please try again.');
                // Keep the pending secret so they can retry
                break;
            }
            break;

        case 'disable_2fa':
            $code = trim($_POST['totp_code'] ?? '');
            $stmt = $db->prepare('SELECT totp_secret FROM admins WHERE id = ?');
            $stmt->execute([$_SESSION['admin_id']]);
            $currentSecret = $stmt->fetchColumn();

            if ($currentSecret && verifyTotpCode($currentSecret, $code)) {
                $stmt = $db->prepare('UPDATE admins SET totp_secret = NULL WHERE id = ?');
                $stmt->execute([$_SESSION['admin_id']]);
                logActivity('2fa_disable');
                setFlash('success', 'Two-factor authentication disabled.');
            } else {
                setFlash('error', 'Invalid code. Enter a valid code to disable 2FA.');
            }
            break;

        case 'change_admin_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_admin_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword)) {
                setFlash('error', 'All password fields are required.');
            } elseif ($newPassword !== $confirmPassword) {
                setFlash('error', 'New passwords do not match.');
            } elseif (strlen($newPassword) < 8) {
                setFlash('error', 'Password must be at least 8 characters.');
            } else {
                $stmt = $db->prepare('SELECT password_hash FROM admins WHERE id = ?');
                $stmt->execute([$_SESSION['admin_id']]);
                $hash = $stmt->fetchColumn();

                if (!password_verify($currentPassword, $hash)) {
                    setFlash('error', 'Current password is incorrect.');
                } else {
                    $stmt = $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                    $stmt->execute([password_hash($newPassword, PASSWORD_BCRYPT), $_SESSION['admin_id']]);
                    logActivity('admin_password_change');
                    setFlash('success', 'Admin password updated.');
                }
            }
            break;
    }

    // Redirect after POST to prevent form resubmission — except for
    // setup_2fa (need to show QR code) and verify_2fa on failure (need to keep showing it)
    if ($action !== 'setup_2fa' && !($action === 'verify_2fa' && !empty($_SESSION['pending_totp_secret']))) {
        $redirectTo = 'dashboard.php';
        if (!empty($_GET['view'])) {
            $redirectTo .= '?view=' . urlencode($_GET['view']);
        }
        redirect($redirectTo);
    }
}

// --- Fetch data for display ---

// Get all boxes with file counts and total sizes
$boxes = $db->query(
    'SELECT b.*, COUNT(f.id) as file_count, COALESCE(SUM(f.size), 0) as total_size
     FROM boxes b
     LEFT JOIN files f ON b.id = f.box_id
     GROUP BY b.id
     ORDER BY b.name'
)->fetchAll();

// Calculate total storage across all boxes
$storageStats = $db->query(
    'SELECT COUNT(*) as total_files, COALESCE(SUM(size), 0) as total_bytes FROM files'
)->fetch();

// Quota presets for the dropdown (value in bytes => label)
$quotaOptions = [
    ''            => 'Unlimited',
    '1073741824'  => '1 GB',
    '5368709120'  => '5 GB',
    '10737418240' => '10 GB',
    '21474836480' => '20 GB',
    '53687091200' => '50 GB',
    '107374182400' => '100 GB',
];

// TTL presets for the dropdown (value in seconds => label)
$ttlOptions = [
    ''       => 'Never',
    '3600'   => '1 hour',
    '21600'  => '6 hours',
    '86400'  => '24 hours',
    '604800' => '7 days',
    '2592000' => '30 days',
];

// If viewing a specific box's files
$viewBox = null;
$viewFiles = [];
if (!empty($_GET['view'])) {
    $stmt = $db->prepare('SELECT * FROM boxes WHERE name = ?');
    $stmt->execute([$_GET['view']]);
    $viewBox = $stmt->fetch();

    if ($viewBox) {
        $stmt = $db->prepare('SELECT * FROM files WHERE box_id = ? ORDER BY uploaded_at DESC');
        $stmt->execute([$viewBox['id']]);
        $viewFiles = $stmt->fetchAll();
    }
}

// Get current admin's 2FA status
$stmt = $db->prepare('SELECT totp_secret FROM admins WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$adminTotpSecret = $stmt->fetchColumn();
$has2fa = !empty($adminTotpSecret);

// --- Activity Log ---
$logFilter = $_GET['log_action'] ?? '';
$logBox = $_GET['log_box'] ?? '';
$logPage = max(1, (int)($_GET['log_page'] ?? 1));
$logPerPage = 25;
$logOffset = ($logPage - 1) * $logPerPage;

$logWhere = '1=1';
$logParams = [];
if ($logFilter) {
    $logWhere .= ' AND action = ?';
    $logParams[] = $logFilter;
}
if ($logBox) {
    $logWhere .= ' AND box_name = ?';
    $logParams[] = $logBox;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE {$logWhere}");
$stmt->execute($logParams);
$logTotal = (int)$stmt->fetchColumn();
$logPages = max(1, ceil($logTotal / $logPerPage));

$stmt = $db->prepare(
    "SELECT * FROM activity_log WHERE {$logWhere} ORDER BY created_at DESC LIMIT {$logPerPage} OFFSET {$logOffset}"
);
$stmt->execute($logParams);
$logEntries = $stmt->fetchAll();

// Get distinct actions and box names for filter dropdowns
$logActions = $db->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$logBoxes = $db->query('SELECT DISTINCT box_name FROM activity_log WHERE box_name IS NOT NULL ORDER BY box_name')->fetchAll(PDO::FETCH_COLUMN);

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> — Admin</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="container">
        <header class="box-header">
            <div>
                <h1><?= e(APP_NAME) ?> Admin</h1>
                <span class="box-label">Logged in as <strong><?= e($_SESSION['admin_username']) ?></strong></span>
            </div>
            <div>
                <a href="../index.php" class="btn btn-secondary">FileDump</a>
                <a href="?action=logout" class="btn btn-secondary">Logout</a>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <!-- Storage Overview -->
        <?php
            $diskTotal = disk_total_space(UPLOAD_DIR);
            $diskFree = disk_free_space(UPLOAD_DIR);
            $diskUsed = $diskTotal - $diskFree;
            $diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100) : 0;
            $diskClass = $diskPercent >= 90 ? 'quota-danger' : ($diskPercent >= 70 ? 'quota-warning' : '');
        ?>
        <div class="admin-section">
            <h2>Storage Overview</h2>
            <div class="stats-row">
                <div class="stat-card">
                    <span class="stat-value"><?= count($boxes) ?></span>
                    <span class="stat-label">Boxes</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= (int)$storageStats['total_files'] ?></span>
                    <span class="stat-label">Files</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= formatFileSize($storageStats['total_bytes']) ?></span>
                    <span class="stat-label">FileDump Usage</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= formatFileSize($diskFree) ?></span>
                    <span class="stat-label">Disk Free</span>
                </div>
            </div>
            <div class="quota-bar" style="margin-top: 1rem;">
                <div class="quota-info">
                    <span>Disk: <?= formatFileSize($diskUsed) ?> of <?= formatFileSize($diskTotal) ?> used</span>
                    <span><?= $diskPercent ?>%</span>
                </div>
                <div class="progress-bar-track">
                    <div class="progress-bar-fill <?= $diskClass ?>" style="width: <?= $diskPercent ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Create New Box -->
        <div class="admin-section">
            <h2>Create New Box</h2>
            <form method="POST" action="" class="inline-form-row">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_box">
                <input type="text" name="box_name" placeholder="url-slug (lowercase, no spaces)"
                       pattern="[a-z0-9\-]+" title="Lowercase letters, numbers, and dashes only" required>
                <input type="text" name="display_name" placeholder="Display Name" required>
                <input type="password" name="box_password" placeholder="Password" required>
                <button type="submit" class="btn btn-primary">Create</button>
            </form>
        </div>

        <!-- Box List -->
        <div class="admin-section">
            <h2>All Boxes</h2>
            <table class="file-table boxes-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Display Name</th>
                        <th>Files</th>
                        <th>Size</th>
                        <th>Quota</th>
                        <th>Auto-Expiry</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($boxes as $box): ?>
                        <tr>
                            <td data-label="Name"><a href="?view=<?= e($box['name']) ?>"><?= e($box['name']) ?></a></td>
                            <td data-label="Display"><?= e($box['display_name']) ?></td>
                            <td data-label="Files"><?= (int)$box['file_count'] ?></td>
                            <td data-label="Size"><?= formatFileSize($box['total_size']) ?></td>
                            <td data-label="Quota">
                                <form method="POST" action="" class="inline-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_quota">
                                    <input type="hidden" name="box_id" value="<?= (int)$box['id'] ?>">
                                    <select name="quota" class="input-small" data-autosubmit>
                                        <?php foreach ($quotaOptions as $val => $label): ?>
                                            <option value="<?= e($val) ?>"
                                                <?= (string)($box['quota'] ?? '') === (string)$val ? 'selected' : '' ?>
                                            ><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td data-label="Auto-Expiry">
                                <form method="POST" action="" class="inline-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_ttl">
                                    <input type="hidden" name="box_id" value="<?= (int)$box['id'] ?>">
                                    <select name="default_ttl" class="input-small" data-autosubmit>
                                        <?php foreach ($ttlOptions as $val => $label): ?>
                                            <option value="<?= e($val) ?>"
                                                <?= (string)($box['default_ttl'] ?? '') === (string)$val ? 'selected' : '' ?>
                                            ><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td class="file-actions">
                                <!-- Change Password -->
                                <form method="POST" action="" class="inline-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="change_password">
                                    <input type="hidden" name="box_id" value="<?= (int)$box['id'] ?>">
                                    <input type="password" name="new_password" placeholder="New password"
                                           class="input-small" required>
                                    <button type="submit" class="btn btn-small">Update</button>
                                </form>
                                <!-- Delete Box -->
                                <form method="POST" action="" class="inline-form"
                                      data-confirm="Delete box &quot;<?= e($box['name']) ?>&quot; and ALL its files?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_box">
                                    <input type="hidden" name="box_id" value="<?= (int)$box['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Delete Box</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($viewBox): ?>
        <!-- Files in Selected Box -->
        <div class="admin-section">
            <h2>Files in "<?= e($viewBox['display_name']) ?>"</h2>

            <?php if (empty($viewFiles)): ?>
                <p class="empty-state">No files in this box.</p>
            <?php else: ?>
                <form method="POST" action="?view=<?= e($viewBox['name']) ?>"
                      id="bulk-form"
                      data-confirm="Delete selected files?">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="bulk_delete">

                    <div class="bulk-actions">
                        <label class="select-all-label">
                            <input type="checkbox" id="select-all"> Select all
                        </label>
                        <button type="submit" class="btn btn-small btn-danger">Delete Selected</button>
                    </div>

                    <table class="file-table">
                        <thead>
                            <tr>
                                <th class="col-check"></th>
                                <th class="col-name">Name</th>
                                <th>Size</th>
                                <th>Uploaded</th>
                                <th>Expires</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewFiles as $file): ?>
                                <tr>
                                    <td><input type="checkbox" name="file_ids[]" value="<?= (int)$file['id'] ?>" class="file-checkbox"></td>
                                    <td class="file-name"><?= e($file['original_name']) ?></td>
                                    <td><?= formatFileSize($file['size']) ?></td>
                                    <td><?= e($file['uploaded_at']) ?></td>
                                    <td><?= $file['expires_at'] ? e($file['expires_at']) : '<span class="text-muted">Never</span>' ?></td>
                                    <td>
                                        <form method="POST" action="?view=<?= e($viewBox['name']) ?>"
                                              class="inline-form"
                                              data-confirm="Delete this file?">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>">
                                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>

            <?php endif; ?>
        </div>
        <?php endif; ?>
        <!-- Admin Settings -->
        <div class="admin-section">
            <h2>Admin Settings</h2>

            <div class="admin-settings-grid">
                <!-- Change Admin Password -->
                <div class="admin-setting">
                    <h3>Change Password</h3>
                    <form method="POST" action="">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="change_admin_password">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_admin_password">New Password</label>
                            <input type="password" id="new_admin_password" name="new_admin_password" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>

                <!-- Two-Factor Authentication -->
                <div class="admin-setting">
                    <h3>Two-Factor Authentication</h3>

                    <?php if (!empty($_SESSION['pending_totp_secret'])): ?>
                        <!-- 2FA Setup: Show QR code -->
                        <?php
                            $pendingSecret = $_SESSION['pending_totp_secret'];
                            $totpUri = buildTotpUri($pendingSecret, $_SESSION['admin_username']);
                        ?>
                        <p style="font-size: 0.875rem; margin-bottom: 0.75rem;">
                            Scan this QR code with your authenticator app, then enter the 6-digit code to verify.
                        </p>
                        <div class="totp-qr" id="totp-qr" data-uri="<?= e($totpUri) ?>"></div>
                        <p class="totp-manual">
                            Manual entry: <code><?= e($pendingSecret) ?></code>
                        </p>
                        <form method="POST" action="">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="verify_2fa">
                            <div class="form-group">
                                <label for="totp_code">Verification Code</label>
                                <input type="text" id="totp_code" name="totp_code"
                                       placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                                       inputmode="numeric" required autofocus
                                       style="text-align: center; font-size: 1.25rem; letter-spacing: 0.3rem;">
                            </div>
                            <button type="submit" class="btn btn-primary">Verify & Enable</button>
                        </form>

                    <?php elseif ($has2fa): ?>
                        <!-- 2FA is enabled -->
                        <div class="alert alert-success" style="margin-bottom: 1rem;">
                            Two-factor authentication is enabled.
                        </div>
                        <form method="POST" action="">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="disable_2fa">
                            <div class="form-group">
                                <label for="disable_totp_code">Enter code to disable 2FA</label>
                                <input type="text" id="disable_totp_code" name="totp_code"
                                       placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                                       inputmode="numeric" required
                                       style="text-align: center; font-size: 1.25rem; letter-spacing: 0.3rem;">
                            </div>
                            <button type="submit" class="btn btn-danger">Disable 2FA</button>
                        </form>

                    <?php else: ?>
                        <!-- 2FA not set up -->
                        <p style="font-size: 0.875rem; color: var(--color-text-light); margin-bottom: 1rem;">
                            Add an extra layer of security by requiring a code from your
                            authenticator app (Google Authenticator, Microsoft Authenticator, etc.)
                            each time you log in.
                        </p>
                        <form method="POST" action="">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="setup_2fa">
                            <button type="submit" class="btn btn-primary">Set Up 2FA</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activity Log -->
        <div class="admin-section" id="activity-log">
            <h2>Activity Log</h2>

            <!-- Filters -->
            <form method="GET" action="#activity-log" class="log-filters">
                <?php if (!empty($_GET['view'])): ?>
                    <input type="hidden" name="view" value="<?= e($_GET['view']) ?>">
                <?php endif; ?>
                <select name="log_action" class="input-small" data-autosubmit>
                    <option value="">All actions</option>
                    <?php foreach ($logActions as $a): ?>
                        <option value="<?= e($a) ?>" <?= $logFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="log_box" class="input-small" data-autosubmit>
                    <option value="">All boxes</option>
                    <?php foreach ($logBoxes as $b): ?>
                        <option value="<?= e($b) ?>" <?= $logBox === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="text-muted" style="font-size: 0.75rem;"><?= $logTotal ?> entries</span>
            </form>

            <?php if (empty($logEntries)): ?>
                <p class="empty-state">No activity recorded yet.</p>
            <?php else: ?>
                <table class="file-table log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Action</th>
                            <th>Box</th>
                            <th class="col-name">File</th>
                            <th>Size</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logEntries as $entry): ?>
                            <tr>
                                <td style="white-space:nowrap" data-utc="<?= e($entry['created_at']) ?>"><?= e($entry['created_at']) ?></td>
                                <td><span class="log-action log-<?= e($entry['action']) ?>"><?= e($entry['action']) ?></span></td>
                                <td><?= $entry['box_name'] ? e($entry['box_name']) : '' ?></td>
                                <td class="file-name"><?= $entry['file_name'] ? e($entry['file_name']) : '' ?></td>
                                <td style="white-space:nowrap"><?= $entry['file_size'] ? formatFileSize($entry['file_size']) : '' ?></td>
                                <td style="white-space:nowrap"><?= e($entry['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($logPages > 1): ?>
                    <div class="log-pagination">
                        <?php
                            $baseParams = [];
                            if (!empty($_GET['view'])) $baseParams['view'] = $_GET['view'];
                            if ($logFilter) $baseParams['log_action'] = $logFilter;
                            if ($logBox) $baseParams['log_box'] = $logBox;
                        ?>
                        <?php if ($logPage > 1): ?>
                            <a href="?<?= http_build_query(array_merge($baseParams, ['log_page' => $logPage - 1])) ?>#activity-log"
                               class="btn btn-small btn-secondary">&laquo; Prev</a>
                        <?php endif; ?>
                        <span class="text-muted" style="font-size: 0.8125rem;">
                            Page <?= $logPage ?> of <?= $logPages ?>
                        </span>
                        <?php if ($logPage < $logPages): ?>
                            <a href="?<?= http_build_query(array_merge($baseParams, ['log_page' => $logPage + 1])) ?>#activity-log"
                               class="btn btn-small btn-secondary">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="../assets/admin.js"></script>
    <script src="../assets/localtime.js"></script>
    <script src="../assets/theme.js"></script>
    <script src="../assets/qrcode.min.js"></script>
    <script src="../assets/totp-setup.js"></script>
</body>
</html>
