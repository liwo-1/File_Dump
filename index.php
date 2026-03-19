<?php
/**
 * Index / Box Login Page
 *
 * This is the main entry point. Users enter a box name and password
 * to access their file box. If they're already logged in, they get
 * redirected to the box view.
 */

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';

// Initialize database on first visit (creates tables if needed)
initDatabase();
initSession();

// If already logged into a box, go straight there
if (isBoxLoggedIn()) {
    redirect('box.php');
}

$error = '';

// Pre-fill the "main" box if no box name is specified in the URL.
// This means visiting the site shows the main box login directly —
// users just enter the password without needing to know the box name.
$defaultBox = $_GET['box'] ?? DEFAULT_BOX;

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $boxName = trim($_POST['box_name'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($boxName) || empty($password)) {
            $error = 'Please enter both box name and password.';
        } elseif (isRateLimited()) {
            $error = 'Too many login attempts. Please wait 15 minutes.';
        } elseif (loginBox($boxName, $password)) {
            redirect('box.php');
        } else {
            $error = 'Invalid box name or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> — Login</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <div class="login-card">
            <h1><?= e(APP_NAME) ?></h1>
            <p class="subtitle">Enter your box credentials</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="box_name">Box Name</label>
                    <input type="text" id="box_name" name="box_name"
                           value="<?= e($_POST['box_name'] ?? $defaultBox) ?>"
                           placeholder="e.g. main" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           placeholder="Enter box password" required>
                </div>
                <button type="submit" class="btn btn-primary">Open Box</button>
            </form>
        </div>
    </div>
    <script src="assets/theme.js"></script>
</body>
</html>
