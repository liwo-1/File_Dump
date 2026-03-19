/**
 * FileDump — Delete Confirmation Modal
 *
 * Replaces the browser's native confirm() dialog with a styled modal
 * for file deletion. Intercepts delete form submissions, shows the
 * modal, and only submits the form if the user clicks "Delete".
 */
(function () {
    'use strict';

    const modal = document.getElementById('delete-modal');
    const fileNameEl = document.getElementById('delete-file-name');
    const confirmBtn = document.getElementById('delete-confirm-btn');
    const cancelBtn = document.getElementById('delete-cancel-btn');
    const closeBtn = document.getElementById('delete-modal-close');

    if (!modal) return;

    let pendingForm = null;

    // Use event delegation — catches submit on any .delete-form,
    // including forms added dynamically after AJAX file list refresh.
    document.addEventListener('submit', e => {
        const form = e.target.closest('.delete-form');
        if (!form) return;

        e.preventDefault();
        pendingForm = form;

        const btn = form.querySelector('[data-file-name]');
        const name = btn ? btn.dataset.fileName : 'this file';
        fileNameEl.textContent = name;

        modal.style.display = 'flex';
    });

    // No-op — kept for compatibility but delegation handles everything
    window.initConfirmUI = function() {};

    // Confirm delete
    confirmBtn.addEventListener('click', () => {
        modal.style.display = 'none';
        if (pendingForm) {
            pendingForm.submit();
            pendingForm = null;
        }
    });

    // Cancel / close
    function closeModal() {
        modal.style.display = 'none';
        pendingForm = null;
    }

    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });
})();
