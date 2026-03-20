/**
 * Admin Dashboard — Event Handlers
 *
 * Replaces inline JS handlers (onchange, onsubmit) that are blocked by
 * the Content Security Policy (script-src 'self'). Instead of inline code,
 * we use data- attributes and attach listeners from this external file.
 *
 * data-autosubmit  — on <select>: submit the parent form when changed
 * data-confirm     — on <form>: show a confirmation dialog before submitting
 */

// Auto-submit: when a <select> with data-autosubmit changes, submit its form
document.querySelectorAll('select[data-autosubmit]').forEach(function(select) {
    select.addEventListener('change', function() {
        this.form.submit();
    });
});

// Confirm dialogs: when a <form> with data-confirm is submitted, ask first
document.querySelectorAll('form[data-confirm]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        if (!confirm(this.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
});

// Select-all checkbox for bulk file operations
var selectAll = document.getElementById('select-all');
if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.file-checkbox').forEach(function(cb) {
            cb.checked = selectAll.checked;
        });
    });
}
