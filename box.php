<?php
/**
 * Box View — File listing, upload, and delete
 *
 * This page shows all files in the currently logged-in box.
 * Users can upload new files, download existing ones, and delete files.
 * Requires box authentication (redirects to login if not authenticated).
 */

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/helpers.php';

initDatabase();
initSession();

// Auth check — if not logged in, send back to login page
if (!isBoxLoggedIn()) {
    redirect('index.php');
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
    redirect('index.php');
}

$boxId = getCurrentBoxId();
$boxName = getCurrentBoxName();
$flash = getFlash();

// Fetch all files for this box, newest first
$db = getDB();
$stmt = $db->prepare('SELECT * FROM files WHERE box_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$boxId]);
$files = $stmt->fetchAll();

// Calculate total size
$totalSize = array_sum(array_column($files, 'size'));

// Get quota info for this box
$quotaInfo = checkBoxQuota($boxId);

// Check if any file has an expiry (to conditionally show the column)
$hasExpiry = false;
foreach ($files as $f) {
    if (!empty($f['expires_at'])) { $hasExpiry = true; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> — <?= e($boxName) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <header class="box-header">
            <div>
                <h1><?= e(APP_NAME) ?></h1>
                <span class="box-label">Box: <strong><?= e($boxName) ?></strong></span>
            </div>
            <a href="?action=logout" class="btn btn-secondary">Logout</a>
        </header>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <!-- Upload Section -->
        <div class="upload-section">
            <h2>Upload File</h2>

            <!-- Drag-and-drop zone + file picker (JavaScript-enhanced) -->
            <div id="drop-zone" class="drop-zone">
                <div class="drop-zone-text">
                    <p class="drop-zone-prompt">Drag & drop files here, or paste an image</p>
                    <p class="drop-zone-or">or</p>
                </div>
                <form id="upload-form" method="POST" action="api/upload.php" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="upload-controls">
                        <input type="file" id="file" name="file" multiple required>
                        <select id="upload-ttl" name="ttl" class="input-small" title="Auto-delete after...">
                            <option value="">No expiry</option>
                            <option value="3600">1 hour</option>
                            <option value="21600">6 hours</option>
                            <option value="86400">24 hours</option>
                            <option value="604800">7 days</option>
                        </select>
                        <button type="submit" id="upload-btn" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>

            <!-- Upload queue (hidden until uploads start) -->
            <div id="upload-queue" class="upload-queue" style="display: none;">
                <div class="upload-queue-header">
                    <h3>Upload Queue</h3>
                    <button id="queue-clear-btn" class="btn btn-small btn-secondary" style="display: none;">Clear Done</button>
                </div>
                <div id="upload-queue-list"></div>
            </div>

            <!-- Fallback for users with JavaScript disabled -->
            <noscript>
                <form method="POST" action="api/upload.php" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="upload-area">
                        <input type="file" name="file" required>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                    <p class="upload-note">Max file size: <?= ini_get('upload_max_filesize') ?> (enable JavaScript for larger files)</p>
                </form>
            </noscript>
        </div>

        <!-- File List -->
        <div class="file-section">
            <h2>Files <span class="file-count">(<?= count($files) ?> files, <?= formatFileSize($totalSize) ?>)</span></h2>
            <?php if ($quotaInfo['quota'] !== null): ?>
                <?php
                    $quotaPercent = $quotaInfo['quota'] > 0 ? round(($quotaInfo['used'] / $quotaInfo['quota']) * 100) : 0;
                    $quotaClass = $quotaPercent >= 90 ? 'quota-danger' : ($quotaPercent >= 70 ? 'quota-warning' : '');
                ?>
                <div class="quota-bar">
                    <div class="quota-info">
                        <span><?= formatFileSize($quotaInfo['used']) ?> of <?= formatFileSize($quotaInfo['quota']) ?> used</span>
                        <span><?= $quotaPercent ?>%</span>
                    </div>
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill <?= $quotaClass ?>" style="width: <?= $quotaPercent ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($files)): ?>
                <p class="empty-state">No files yet. Upload something!</p>
            <?php else: ?>
                <table class="file-table">
                    <thead>
                        <tr>
                            <th class="col-name">Name</th>
                            <th class="col-size">Size</th>
                            <th class="col-date">Uploaded</th>
                            <?php if ($hasExpiry): ?><th class="col-expires">Expires</th><?php endif; ?>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $textTypes = ['text/plain', 'text/csv'];
                        $textExts = ['txt', 'md', 'csv', 'log', 'json', 'xml', 'html', 'css', 'js', 'php', 'sh', 'py'];
                        foreach ($files as $file):
                            $mime = $file['mime_type'] ?? '';
                            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                            $isImage = in_array($mime, $imageTypes);
                            $isText = in_array($mime, $textTypes) || in_array($ext, $textExts);
                            $isPreviewable = $isImage || $isText || $mime === 'application/pdf';
                        ?>
                            <tr>
                                <td class="file-name">
                                    <div class="file-name-inner">
                                        <?php if ($isImage): ?>
                                            <img src="api/download.php?id=<?= (int)$file['id'] ?>&preview"
                                                 class="file-thumbnail" alt=""
                                                 loading="lazy">
                                        <?php endif; ?>
                                        <span class="file-name-text"><?= e($file['original_name']) ?></span>
                                    </div>
                                </td>
                                <td><?= formatFileSize($file['size']) ?></td>
                                <td><?= e($file['uploaded_at']) ?></td>
                                <?php if ($hasExpiry): ?><td><?= !empty($file['expires_at']) ? e($file['expires_at']) : '' ?></td><?php endif; ?>
                                <td class="file-actions">
                                    <?php if ($isPreviewable): ?>
                                        <a href="api/download.php?id=<?= (int)$file['id'] ?>&preview"
                                           target="_blank"
                                           class="btn btn-small btn-secondary">Preview</a>
                                    <?php endif; ?>
                                    <a href="api/download.php?id=<?= (int)$file['id'] ?>"
                                       class="btn btn-small btn-secondary">Download</a>
                                    <button class="btn btn-small btn-secondary share-btn"
                                            data-file-id="<?= (int)$file['id'] ?>"
                                            data-file-name="<?= e($file['original_name']) ?>">Share</button>
                                    <form method="POST" action="api/delete.php" class="inline-form delete-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>">
                                        <button type="submit" class="btn btn-small btn-danger"
                                                data-file-name="<?= e($file['original_name']) ?>">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <!-- Delete Confirm Modal -->
    <div id="delete-modal" class="modal" style="display: none;">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h3>Delete File</h3>
                <button class="modal-close" id="delete-modal-close">&times;</button>
            </div>
            <p>Are you sure you want to delete <strong id="delete-file-name"></strong>?</p>
            <p class="text-muted" style="margin-top: 0.25rem; font-size: 0.8125rem;">This action cannot be undone.</p>
            <div class="modal-actions">
                <button id="delete-cancel-btn" class="btn btn-secondary">Cancel</button>
                <button id="delete-confirm-btn" class="btn btn-danger">Delete</button>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    <div id="share-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Share File</h3>
                <button class="modal-close" id="share-modal-close">&times;</button>
            </div>
            <div id="share-modal-body">
                <p>File: <strong id="share-file-name"></strong></p>
                <label for="share-ttl">Link expires after:</label>
                <select id="share-ttl" class="input-small">
                    <option value="3600">1 hour</option>
                    <option value="21600">6 hours</option>
                    <option value="86400" selected>24 hours</option>
                    <option value="259200">3 days</option>
                    <option value="604800">7 days</option>
                </select>
                <button id="share-create-btn" class="btn btn-primary">Create Link</button>
            </div>
            <div id="share-existing" style="display: none;">
                <h4>Existing Links</h4>
                <div id="share-existing-list"></div>
            </div>
            <div id="share-result" style="display: none;">
                <p>File: <strong id="share-result-name"></strong></p>
                <p>Expires: <span id="share-result-expires"></span></p>
                <div class="share-url-actions">
                    <button id="share-copy-btn" class="btn btn-small btn-primary">Copy Link</button>
                    <a id="share-open-link" href="#" target="_blank" class="btn btn-small btn-secondary">Open Link</a>
                </div>
                <p id="share-copied" class="share-copied" style="display: none;">Copied to clipboard!</p>
                <div class="share-url-box">
                    <code id="share-url-text" class="share-url-display"></code>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/theme.js"></script>
    <script src="assets/app.js"></script>
    <script src="assets/share.js"></script>
    <script src="assets/confirm.js"></script>
</body>
</html>
