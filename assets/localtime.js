/**
 * Convert UTC timestamps to the viewer's local timezone.
 *
 * SQLite stores all timestamps in UTC. This script finds elements marked
 * with a data-utc attribute and replaces their text with the equivalent
 * local time. For example, if you're in CET (UTC+1), "2026-03-20 11:00:00"
 * becomes "2026-03-20 12:00:00".
 *
 * This runs automatically on page load — no configuration needed.
 */
document.querySelectorAll('td[data-utc]').forEach(function(td) {
    var utc = td.getAttribute('data-utc');
    // Append 'Z' so the Date constructor treats it as UTC, not local time
    var d = new Date(utc.replace(' ', 'T') + 'Z');
    if (!isNaN(d)) {
        var year = d.getFullYear();
        var month = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        var hours = String(d.getHours()).padStart(2, '0');
        var mins = String(d.getMinutes()).padStart(2, '0');
        var secs = String(d.getSeconds()).padStart(2, '0');
        td.textContent = year + '-' + month + '-' + day + ' ' + hours + ':' + mins + ':' + secs;
    }
});
