/**
 * FileDump — Upload Client with Multi-File Queue
 *
 * Handles file uploads with a background queue system:
 * - Select or drop multiple files at once
 * - Each file gets its own progress row in the queue panel
 * - Files upload one at a time (sequential)
 * - Large files are chunked (100MB per chunk)
 * - Pause/resume and cancel per file
 * - Paste images from clipboard
 * - Chunks cleaned up on cancel or tab close
 */

(function () {
    'use strict';

    // --- Configuration ---
    const CHUNK_SIZE = 50 * 1024 * 1024;
    const MAX_RETRIES = 3;
    const RETRY_DELAY = 2000;
    const PARALLEL_CHUNKS = 3;  // Upload this many chunks simultaneously

    // --- DOM Elements ---
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file');
    const uploadBtn = document.getElementById('upload-btn');
    const uploadForm = document.getElementById('upload-form');
    const queueContainer = document.getElementById('upload-queue');
    const queueList = document.getElementById('upload-queue-list');
    const clearBtn = document.getElementById('queue-clear-btn');

    if (!dropZone || !fileInput) return;

    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const ttlSelect = document.getElementById('upload-ttl');

    // --- Upload Queue State ---
    const queue = [];       // Array of upload objects
    let isProcessing = false;

    // =====================================================================
    // Event Handlers
    // =====================================================================

    // Drag-and-drop
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(event => {
        dropZone.addEventListener(event, e => { e.preventDefault(); e.stopPropagation(); });
    });
    ['dragenter', 'dragover'].forEach(event => {
        dropZone.addEventListener(event, () => dropZone.classList.add('drag-over'));
    });
    ['dragleave', 'drop'].forEach(event => {
        dropZone.addEventListener(event, () => dropZone.classList.remove('drag-over'));
    });

    dropZone.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        if (files.length > 0) addFilesToQueue(Array.from(files));
    });

    // File input (supports multiple)
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            addFilesToQueue(Array.from(fileInput.files));
            fileInput.value = ''; // Reset so same file can be selected again
        }
    });

    // Form submit
    if (uploadForm) {
        uploadForm.addEventListener('submit', e => {
            e.preventDefault();
            if (fileInput.files.length > 0) {
                addFilesToQueue(Array.from(fileInput.files));
                fileInput.value = '';
            }
        });
    }

    // Clipboard paste
    document.addEventListener('paste', e => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        const items = e.clipboardData?.items;
        if (!items) return;

        for (const item of items) {
            if (item.type.startsWith('image/')) {
                e.preventDefault();
                const blob = item.getAsFile();
                if (!blob) continue;

                const now = new Date();
                const stamp = now.getFullYear()
                    + '-' + String(now.getMonth() + 1).padStart(2, '0')
                    + '-' + String(now.getDate()).padStart(2, '0')
                    + '-' + String(now.getHours()).padStart(2, '0')
                    + String(now.getMinutes()).padStart(2, '0')
                    + String(now.getSeconds()).padStart(2, '0');
                const ext = blob.type.split('/')[1] || 'png';
                const file = new File([blob], `paste-${stamp}.${ext}`, { type: blob.type });

                dropZone.classList.add('drag-over');
                setTimeout(() => dropZone.classList.remove('drag-over'), 300);

                addFilesToQueue([file]);
                break;
            }
        }
    });

    // Clear completed uploads
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            for (let i = queue.length - 1; i >= 0; i--) {
                if (queue[i].status === 'done' || queue[i].status === 'error') {
                    queue[i].element.remove();
                    queue.splice(i, 1);
                }
            }
            if (queue.length === 0) {
                queueContainer.style.display = 'none';
            }
            updateClearButton();
        });
    }

    // Clean up active uploads when tab closes
    window.addEventListener('beforeunload', () => {
        queue.forEach(upload => {
            if (upload.uploadId && upload.status === 'uploading') {
                const data = new FormData();
                data.append('action', 'cancel');
                data.append('upload_id', upload.uploadId);
                data.append('csrf_token', csrfToken);
                navigator.sendBeacon('api/upload-chunk.php?action=cancel', data);
            }
        });
    });

    // =====================================================================
    // Queue Management
    // =====================================================================

    function addFilesToQueue(files) {
        files.forEach(file => {
            const upload = {
                file: file,
                status: 'pending',   // pending, uploading, paused, done, error
                uploadId: null,
                bytesSent: 0,
                cancelled: false,
                paused: false,
                resumeResolve: null,
                element: null,
            };

            // Create queue row UI
            upload.element = createQueueRow(upload);
            queueList.appendChild(upload.element);
            queue.push(upload);
        });

        queueContainer.style.display = 'block';
        processQueue();
    }

    async function processQueue() {
        if (isProcessing) return;
        isProcessing = true;

        while (true) {
            const next = queue.find(u => u.status === 'pending');
            if (!next) break;

            next.status = 'uploading';
            updateQueueRowStatus(next);

            try {
                if (next.file.size <= CHUNK_SIZE) {
                    await uploadSimple(next);
                } else {
                    await uploadChunked(next);
                }

                if (!next.cancelled) {
                    next.status = 'done';
                    updateQueueRowStatus(next);
                    refreshFileList();
                }
            } catch (err) {
                if (next.cancelled) {
                    next.status = 'error';
                    next.errorMessage = 'Cancelled';
                } else {
                    next.status = 'error';
                    next.errorMessage = err.message;
                }
                updateQueueRowStatus(next);
            }

            updateClearButton();
        }

        isProcessing = false;
    }

    // =====================================================================
    // Queue Row UI
    // =====================================================================

    function createQueueRow(upload) {
        const row = document.createElement('div');
        row.className = 'queue-item';

        row.innerHTML = `
            <div class="queue-item-info">
                <span class="queue-item-name">${escapeHtml(upload.file.name)}</span>
                <span class="queue-item-size">${formatSize(upload.file.size)}</span>
            </div>
            <div class="queue-item-progress">
                <div class="progress-bar-track">
                    <div class="progress-bar-fill" style="width: 0%"></div>
                </div>
                <div class="queue-item-detail">
                    <span class="queue-item-status">Waiting...</span>
                    <span class="queue-item-speed"></span>
                </div>
            </div>
            <div class="queue-item-actions">
                <button class="btn btn-small btn-secondary queue-pause-btn" style="display:none;">Pause</button>
                <button class="btn btn-small btn-danger queue-cancel-btn">Cancel</button>
            </div>
        `;

        // Cancel button
        const cancelBtn = row.querySelector('.queue-cancel-btn');
        cancelBtn.addEventListener('click', () => {
            upload.cancelled = true;
            // Abort any in-flight fetch requests immediately
            if (upload.abortController) {
                upload.abortController.abort();
            }
            if (upload.uploadId) {
                cancelUploadOnServer(upload.uploadId);
                upload.uploadId = null;
            }
            if (upload.status === 'pending') {
                upload.status = 'error';
                upload.errorMessage = 'Cancelled';
                updateQueueRowStatus(upload);
                updateClearButton();
            } else if (upload.paused && upload.resumeResolve) {
                upload.resumeResolve();
            }
        });

        // Pause/Resume button
        const pauseBtn = row.querySelector('.queue-pause-btn');
        pauseBtn.addEventListener('click', () => {
            if (upload.paused) {
                upload.paused = false;
                pauseBtn.textContent = 'Pause';
                upload.status = 'uploading';
                if (upload.resumeResolve) {
                    upload.resumeResolve();
                    upload.resumeResolve = null;
                }
            } else {
                upload.paused = true;
                upload.status = 'paused';
                pauseBtn.textContent = 'Resume';
                updateQueueRowStatus(upload);
            }
        });

        return row;
    }

    function updateQueueRowStatus(upload) {
        const row = upload.element;
        if (!row) return;

        const statusEl = row.querySelector('.queue-item-status');
        const speedEl = row.querySelector('.queue-item-speed');
        const bar = row.querySelector('.progress-bar-fill');
        const pauseBtn = row.querySelector('.queue-pause-btn');
        const cancelBtn = row.querySelector('.queue-cancel-btn');

        row.className = 'queue-item queue-' + upload.status;

        switch (upload.status) {
            case 'pending':
                statusEl.textContent = 'Waiting...';
                speedEl.textContent = '';
                break;
            case 'uploading':
                pauseBtn.style.display = 'inline-block';
                break;
            case 'paused':
                statusEl.textContent = 'Paused';
                speedEl.textContent = '';
                break;
            case 'done':
                bar.style.width = '100%';
                statusEl.textContent = 'Complete';
                speedEl.textContent = '';
                pauseBtn.style.display = 'none';
                cancelBtn.style.display = 'none';
                break;
            case 'error':
                statusEl.textContent = upload.errorMessage || 'Failed';
                speedEl.textContent = '';
                pauseBtn.style.display = 'none';
                cancelBtn.style.display = 'none';
                break;
        }
    }

    function updateQueueRowProgress(upload, loaded, total, speed, eta) {
        const row = upload.element;
        if (!row) return;

        const percent = total > 0 ? Math.round((loaded / total) * 100) : 0;
        const bar = row.querySelector('.progress-bar-fill');
        const statusEl = row.querySelector('.queue-item-status');
        const speedEl = row.querySelector('.queue-item-speed');

        bar.style.width = percent + '%';
        statusEl.textContent = `${formatSize(loaded)} / ${formatSize(total)} (${percent}%)`;

        if (speed) {
            const etaStr = eta > 0 ? ` — ${formatTime(eta)} left` : '';
            speedEl.textContent = `${formatSize(speed)}/s${etaStr}`;
        }
    }

    function updateClearButton() {
        const hasDone = queue.some(u => u.status === 'done' || u.status === 'error');
        if (clearBtn) clearBtn.style.display = hasDone ? 'inline-block' : 'none';
    }

    // =====================================================================
    // Simple Upload (small files)
    // =====================================================================

    function uploadSimple(upload) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('file', upload.file);
            formData.append('csrf_token', csrfToken);
            if (ttlSelect && ttlSelect.value) {
                formData.append('ttl', ttlSelect.value);
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api/upload.php');

            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) {
                    updateQueueRowProgress(upload, e.loaded, e.total);
                }
            });

            xhr.addEventListener('load', () => resolve());
            xhr.addEventListener('error', () => reject(new Error('Upload failed')));

            xhr.send(formData);
        });
    }

    // =====================================================================
    // Chunked Upload (large files)
    // =====================================================================

    async function uploadChunked(upload) {
        const file = upload.file;
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);

        // AbortController lets us cancel in-flight fetch requests immediately
        // when the user clicks Cancel, instead of waiting for them to finish.
        upload.abortController = new AbortController();

        // Step 1: Init
        const initData = {
            file_name: file.name,
            file_size: file.size,
            total_chunks: totalChunks,
            mime_type: file.type || 'application/octet-stream',
        };
        if (ttlSelect && ttlSelect.value) {
            initData.ttl = ttlSelect.value;
        }

        const initResp = await apiPost('api/upload-chunk.php?action=init', initData);
        if (!initResp.success) throw new Error(initResp.error || 'Failed to initialize upload.');

        upload.uploadId = initResp.upload_id;

        // Step 2: Upload chunks in parallel batches
        // Sends PARALLEL_CHUNKS at a time for better throughput.
        // Chunk ordering doesn't matter during upload — the server
        // stores each chunk by index and assembles them in order.
        const startTime = Date.now();
        let nextChunk = 0;

        while (nextChunk < totalChunks) {
            if (upload.cancelled) return;

            // Pause handling
            if (upload.paused) {
                await new Promise(resolve => { upload.resumeResolve = resolve; });
                if (upload.cancelled) return;
            }

            // Build a batch of up to PARALLEL_CHUNKS
            const batch = [];
            for (let j = 0; j < PARALLEL_CHUNKS && nextChunk < totalChunks; j++, nextChunk++) {
                const i = nextChunk;
                const start = i * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);
                batch.push({ index: i, blob: chunk, size: end - start });
            }

            // Upload all chunks in this batch concurrently
            const results = await Promise.all(
                batch.map(c => uploadChunkWithRetry(upload.uploadId, c.index, c.blob, MAX_RETRIES, upload.abortController.signal))
            );

            // Update progress after the batch completes
            const batchBytes = batch.reduce((sum, c) => sum + c.size, 0);
            upload.bytesSent += batchBytes;
            const elapsed = (Date.now() - startTime) / 1000;
            const speed = upload.bytesSent / elapsed;
            const remaining = (file.size - upload.bytesSent) / speed;

            updateQueueRowProgress(upload, upload.bytesSent, file.size, speed, remaining);
        }

        if (upload.cancelled) return;

        // Step 3: Assemble
        const statusEl = upload.element?.querySelector('.queue-item-status');
        const speedEl = upload.element?.querySelector('.queue-item-speed');
        if (statusEl) statusEl.textContent = 'Assembling...';
        if (speedEl) speedEl.textContent = '';

        // Hide pause during assembly
        const pauseBtn = upload.element?.querySelector('.queue-pause-btn');
        if (pauseBtn) pauseBtn.style.display = 'none';

        let assemblySucceeded = false;
        try {
            const completeResp = await apiPost('api/upload-chunk.php?action=complete', {
                upload_id: upload.uploadId,
            });
            assemblySucceeded = completeResp.success;
            if (!assemblySucceeded) throw new Error(completeResp.error || 'Assembly failed.');
        } catch (completeErr) {
            if (statusEl) statusEl.textContent = 'Waiting for assembly...';
            assemblySucceeded = await pollForCompletion(upload.uploadId, 60);
            if (!assemblySucceeded) throw new Error('Assembly timed out.');
        }

        upload.uploadId = null; // Prevent beforeunload cleanup
    }

    // =====================================================================
    // Chunk Upload with Retry
    // =====================================================================

    async function uploadChunkWithRetry(uploadId, index, chunkBlob, retriesLeft, signal) {
        try {
            // If already aborted (cancelled), bail immediately
            if (signal && signal.aborted) throw new DOMException('Aborted', 'AbortError');

            const formData = new FormData();
            formData.append('action', 'chunk');
            formData.append('upload_id', uploadId);
            formData.append('chunk_index', index);
            formData.append('chunk', chunkBlob);

            const resp = await fetch('api/upload-chunk.php?action=chunk', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData,
                signal: signal,
            });

            const contentType = resp.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(`Server returned ${resp.status}`);
            }

            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Chunk failed.');
            return data;

        } catch (err) {
            // Don't retry if the upload was cancelled
            if (err.name === 'AbortError') throw err;

            if (retriesLeft > 0) {
                await sleep(RETRY_DELAY);
                return uploadChunkWithRetry(uploadId, index, chunkBlob, retriesLeft - 1, signal);
            }
            throw new Error(`Chunk ${index} failed after ${MAX_RETRIES} retries: ${err.message}`);
        }
    }

    // =====================================================================
    // API Helpers
    // =====================================================================

    async function pollForCompletion(uploadId, maxChecks) {
        for (let i = 0; i < maxChecks; i++) {
            await sleep(5000);
            try {
                const status = await apiGet(`api/upload-chunk.php?action=status&upload_id=${uploadId}`);
                if (status.success && status.uploaded_chunks.length === 0) return true;
            } catch (e) {}
        }
        return false;
    }

    /**
     * Refresh the file list without a full page reload.
     * Fetches the current page HTML, extracts the updated file section,
     * and swaps it into the DOM so newly uploaded files appear immediately.
     */
    async function refreshFileList() {
        try {
            const resp = await fetch(window.location.href);
            const html = await resp.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newFileSection = doc.querySelector('.file-section');
            const currentFileSection = document.querySelector('.file-section');
            if (newFileSection && currentFileSection) {
                currentFileSection.innerHTML = newFileSection.innerHTML;
                // Re-bind share buttons and delete forms on the new content
                if (typeof window.initShareUI === 'function') window.initShareUI();
                if (typeof window.initConfirmUI === 'function') window.initConfirmUI();
            }
        } catch (e) {
            // Silently fail — files will show on next full page load
        }
    }

    function cancelUploadOnServer(uploadId) {
        if (!uploadId) return;
        const data = new FormData();
        data.append('action', 'cancel');
        data.append('upload_id', uploadId);
        data.append('csrf_token', csrfToken);
        navigator.sendBeacon('api/upload-chunk.php?action=cancel', data);
    }

    async function apiPost(url, data) {
        const formData = new FormData();
        for (const [key, value] of Object.entries(data)) formData.append(key, value);

        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData,
        });

        const contentType = resp.headers.get('content-type') || '';
        if (!contentType.includes('application/json'))
            throw new Error(`Server returned ${resp.status} (non-JSON)`);

        return resp.json();
    }

    async function apiGet(url) {
        const resp = await fetch(url);
        const contentType = resp.headers.get('content-type') || '';
        if (!contentType.includes('application/json'))
            throw new Error(`Server returned ${resp.status} (non-JSON)`);
        return resp.json();
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // =====================================================================
    // Formatting Helpers
    // =====================================================================

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatSize(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        let size = bytes;
        while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
        return size.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function formatTime(seconds) {
        if (seconds < 60) return Math.round(seconds) + 's';
        if (seconds < 3600) return Math.round(seconds / 60) + 'm';
        const h = Math.floor(seconds / 3600);
        const m = Math.round((seconds % 3600) / 60);
        return h + 'h ' + m + 'm';
    }

})();
