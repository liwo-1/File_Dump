<?php
/**
 * Admin Login Page
 *
 * Handles admin authentication with optional 2FA (TOTP).
 *
 * Flow:
 * 1. Enter username + password
 * 2. If 2FA is set up → show code input → verify → dashboard
 * 3. If 2FA not set up → dashboard (with prompt to enable it)
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/totp.php';

initDatabase();
initSession();

// If already fully logged in, go to dashboard
if (isAdminLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';
$showTotpForm = false;

// Check if we're in the "password verified, awaiting 2FA" state
if (!empty($_SESSION['admin_2fa_pending'])) {
    $showTotpForm = true;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code'])) {
        if (!validateCsrf()) {
            $error = 'Invalid form submission.';
        } else {
            $code = trim($_POST['totp_code'] ?? '');
            $db = getDB();
            $stmt = $db->prepare('SELECT totp_secret FROM admins WHERE id = ?');
            $stmt->execute([$_SESSION['admin_2fa_pending']['id']]);
            $secret = $stmt->fetchColumn();

            if ($secret && verifyTotpCode($secret, $code)) {
                // 2FA passed — complete the login
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $_SESSION['admin_2fa_pending']['id'];
                $_SESSION['admin_username'] = $_SESSION['admin_2fa_pending']['username'];
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_login_time'] = time();
                unset($_SESSION['admin_2fa_pending']);
                logActivity('admin_login');
                redirect('dashboard.php');
            } else {
                $error = 'Invalid authentication code.';
            }
        }
    }

    // Cancel 2FA — go back to password form
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_2fa'])) {
        unset($_SESSION['admin_2fa_pending']);
        redirect('index.php');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Step 1: Verify username + password
    if (!validateCsrf()) {
        $error = 'Invalid form submission.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } elseif (isRateLimited()) {
            $error = 'Too many login attempts. Please wait 15 minutes.';
        } else {
            // Check credentials without fully logging in
            $db = getDB();
            $stmt = $db->prepare('SELECT id, username, password_hash, totp_secret FROM admins WHERE username = ?');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                recordFailedAttempt();
                logActivity('login_failed', null, $username);
                $error = 'Invalid username or password.';
            } elseif (!empty($admin['totp_secret'])) {
                // Password correct, 2FA is set up — go to code entry
                $_SESSION['admin_2fa_pending'] = [
                    'id' => $admin['id'],
                    'username' => $admin['username'],
                ];
                $showTotpForm = true;
            } else {
                // Password correct, no 2FA — log in directly
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_login_time'] = time();
                logActivity('admin_login');
                redirect('dashboard.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> — Admin Login</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="container">
        <div class="login-card">
            <h1><?= e(APP_NAME) ?></h1>
            <p class="subtitle">Admin Login</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($showTotpForm): ?>
                <!-- 2FA Code Entry -->
                <p style="margin-bottom: 1rem; font-size: 0.875rem; color: var(--color-text-light);">
                    Enter the 6-digit code from your authenticator app.
                </p>
                <form method="POST" action="">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label for="totp_code">Authentication Code</label>
                        <input type="text" id="totp_code" name="totp_code"
                               placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                               autocomplete="one-time-code" inputmode="numeric"
                               required autofocus
                               style="text-align: center; font-size: 1.5rem; letter-spacing: 0.5rem;">
                    </div>
                    <button type="submit" class="btn btn-primary">Verify</button>
                </form>
                <form method="POST" action="" style="margin-top: 0.5rem;">
                    <?= csrfField() ?>
                    <button type="submit" name="cancel_2fa" value="1"
                            class="btn btn-secondary" style="width: 100%;">Cancel</button>
                </form>
            <?php else: ?>
                <!-- Username + Password -->
                <form method="POST" action="">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username"
                               value="<?= e($_POST['username'] ?? '') ?>"
                               placeholder="Admin username" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password"
                               placeholder="Admin password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Login</button>
                </form>
            <?php endif; ?>

            <p class="back-link"><a href="../index.php">Back to FileDump</a></p>
        </div>
    </div>
    <script src="../assets/theme.js"></script>
</body>
</html>
