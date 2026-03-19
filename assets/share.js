/**
 * FileDump — Share Link UI
 *
 * Handles the share modal: creating share links, displaying the URL,
 * listing existing links, and deleting them. Loaded as an external
 * script so it works with the Content-Security-Policy.
 */
(function() {
    'use strict';

    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const modal = document.getElementById('share-modal');
    const modalBody = document.getElementById('share-modal-body');
    const resultDiv = document.getElementById('share-result');
    const existingDiv = document.getElementById('share-existing');
    const existingList = document.getElementById('share-existing-list');
    const closeBtn = document.getElementById('share-modal-close');
    const createBtn = document.getElementById('share-create-btn');
    const copyBtn = document.getElementById('share-copy-btn');

    if (!modal) return;

    let currentFileId = null;

    // Use event delegation — one listener catches all share button clicks,
    // including buttons added dynamically after AJAX file list refresh.
    document.addEventListener('click', e => {
        const btn = e.target.closest('.share-btn');
        if (!btn) return;

        currentFileId = btn.dataset.fileId;
        document.getElementById('share-file-name').textContent = btn.dataset.fileName;
        modalBody.style.display = 'block';
        resultDiv.style.display = 'none';
        modal.style.display = 'flex';
        loadExistingLinks(currentFileId);
    });

    // No-op — kept for compatibility but delegation handles everything
    window.initShareUI = function() {};

    // Close modal
    if (closeBtn) {
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
    }
    modal.addEventListener('click', e => {
        if (e.target === modal) modal.style.display = 'none';
    });

    // Create share link
    if (createBtn) {
        createBtn.addEventListener('click', async () => {
            const ttl = document.getElementById('share-ttl').value;
            createBtn.disabled = true;
            createBtn.textContent = 'Creating...';

            try {
                const formData = new FormData();
                formData.append('action', 'create');
                formData.append('file_id', currentFileId);
                formData.append('ttl', ttl);
                formData.append('csrf_token', csrfToken);

                const resp = await fetch('api/share.php', {
                    method: 'POST',
                    body: formData,
                });
                const data = await resp.json();

                if (data.success) {
                    document.getElementById('share-url-text').textContent = data.share_url;
                    document.getElementById('share-open-link').href = data.share_url;
                    document.getElementById('share-result-name').textContent = data.file_name;
                    document.getElementById('share-result-expires').textContent = data.expires_at;
                    modalBody.style.display = 'none';
                    existingDiv.style.display = 'none';
                    resultDiv.style.display = 'block';
                } else {
                    alert('Error: ' + (data.error || 'Failed to create share link.'));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                createBtn.disabled = false;
                createBtn.textContent = 'Create Link';
            }
        });
    }

    // Copy link to clipboard
    if (copyBtn) {
        copyBtn.addEventListener('click', () => {
            const copied = document.getElementById('share-copied');
            const shareText = document.getElementById('share-url-text').textContent;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(shareText).then(() => {
                    copied.style.display = 'block';
                    setTimeout(() => copied.style.display = 'none', 2000);
                });
            } else {
                const temp = document.createElement('textarea');
                temp.value = shareText;
                document.body.appendChild(temp);
                temp.select();
                document.execCommand('copy');
                document.body.removeChild(temp);
                copied.style.display = 'block';
                setTimeout(() => copied.style.display = 'none', 2000);
            }
        });
    }

    /**
     * Load existing share links for a file and display them.
     */
    async function loadExistingLinks(fileId) {
        existingList.innerHTML = '';
        existingDiv.style.display = 'none';

        try {
            const resp = await fetch(`api/share.php?action=list&file_id=${fileId}`);
            const data = await resp.json();

            if (!data.success || !data.links || data.links.length === 0) {
                return;
            }

            data.links.forEach(link => {
                const item = document.createElement('div');
                item.className = 'share-link-item' + (link.expired ? ' expired' : '');

                const info = document.createElement('span');
                info.className = 'share-link-info';
                if (link.expired) {
                    info.innerHTML = 'Expired: ' + escapeHtml(link.expires_at) +
                        ' <span class="share-link-expired-tag">EXPIRED</span>';
                } else {
                    info.textContent = 'Expires: ' + link.expires_at;
                }

                const deleteBtn = document.createElement('button');
                deleteBtn.className = 'btn btn-small btn-danger';
                deleteBtn.textContent = 'Delete';
                deleteBtn.addEventListener('click', () => deleteLink(link.id, item));

                item.appendChild(info);
                item.appendChild(deleteBtn);
                existingList.appendChild(item);
            });

            existingDiv.style.display = 'block';
        } catch (err) {
            // Silently fail — existing links are optional info
        }
    }

    /**
     * Delete a share link and remove its row from the list.
     */
    async function deleteLink(linkId, element) {
        try {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('link_id', linkId);
            formData.append('csrf_token', csrfToken);

            const resp = await fetch('api/share.php', {
                method: 'POST',
                body: formData,
            });
            const data = await resp.json();

            if (data.success) {
                element.remove();
                // Hide the section if no links remain
                if (existingList.children.length === 0) {
                    existingDiv.style.display = 'none';
                }
            } else {
                alert('Error: ' + (data.error || 'Failed to delete link.'));
            }
        } catch (err) {
            alert('Error: ' + err.message);
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
