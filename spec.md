# File Dump — Specification

## Overview
A self-hosted, internet-facing web app for temporary file sharing between VMs and workstations. Replaces a password-protected OneDrive shared folder.

**Domain:** files.example.com
**Admin:** files.example.com/admin
**Stack:** PHP 8.x, HTML, CSS, SQLite, Apache2
**Hosting:** Debian 12 VM (headless)
**Reverse Proxy:** Nginx Proxy Manager on separate Docker host (optional)
**SSL:** Let's Encrypt via Nginx Proxy Manager (SSL termination at reverse proxy)
**DNS:** Split DNS recommended for LAN resolution (bypasses NAT hairpin)
**File nature:** Temporary — most files deleted within hours or before next use

---

## Core Concepts

### Boxes
A "box" is a password-protected file container. Users access a box by entering its name and password. Each box has its own isolated file storage. The "main" box is the default, accessed directly via files.example.com.

### Admin Panel
Located at /admin. Allows managing boxes (create, delete, change passwords), viewing storage usage, and general administration. Protected by a separate admin login.

---

## Architecture

```
files.example.com/
├── index.php              # Box login (enter box name + password)
├── box.php                # Box view — upload/download/delete files
├── admin/
│   ├── index.php          # Admin login
│   └── dashboard.php      # Manage boxes, passwords, settings
├── api/
│   ├── upload.php         # File upload endpoint
│   ├── upload-chunk.php   # Chunked upload (init/chunk/complete/status)
│   ├── download.php       # File download (streaming)
│   └── delete.php         # File deletion
├── includes/
│   ├── config.php         # App config (paths, limits)
│   ├── db.php             # SQLite connection + helpers
│   ├── auth.php           # Password hashing, session management
│   └── helpers.php        # CSRF tokens, file size formatting, etc.
├── assets/
│   ├── style.css          # Styling
│   └── app.js             # Upload progress, drag-and-drop
├── data/
│   ├── filedump.db        # SQLite database
│   └── uploads/           # Uploaded files organized by box
│       ├── main/
│       └── {box-name}/
├── cron/
│   └── cleanup.php        # Expired file + stale chunk cleanup
└── .htaccess              # Security rules, URL rewriting
```

## Database Schema (SQLite)

```sql
CREATE TABLE admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE boxes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active INTEGER DEFAULT 1
);

CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    box_id INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    size INTEGER NOT NULL,
    mime_type TEXT,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE CASCADE
);
```

---

## Milestones

### Milestone 1 — Minimal Viable Product
Get it working with core functionality in a dev environment.

**Features:**
- Single "main" box with password protection
- File upload (standard HTML form, up to ~2GB via PHP config)
- File listing with download links
- File deletion
- Admin page at /admin:
  - Create/delete boxes
  - Change box passwords
  - View all boxes and their files
- Basic responsive CSS

**Security (dev baseline):**
- Passwords hashed with bcrypt (`password_hash()` / `password_verify()`)
- CSRF tokens on all POST forms
- Session-based auth with `session_regenerate_id()` on login
- `.htaccess` blocks access to `data/`, `includes/`, SQLite DB
- Prepared SQL statements (no SQL injection)
- File names sanitized on upload (stored with UUID, original name in DB)
- Rate limiting on login attempts

**Limitations:**
- Max file size ~2GB (PHP limit)
- No HTTPS (dev only)
- Basic UI

---

### Milestone 2 — Production Ready
Everything needed to safely expose to the internet.

**Features:**
- Chunked uploads via JavaScript — 50MB chunks, supports 50GB+ files
- Upload progress bar with percentage and speed
- Pause/resume uploads — in-session only; chunks cleaned up on cancel or tab close
- Drag-and-drop upload zone
- Auto-expiry — optional TTL per box or per file, cron job cleans up (every 15 min)
- Session lifetime: 12 hours
- HTTPS via Let's Encrypt — SSL terminated at Nginx Proxy Manager (reverse proxy)
- Improved admin dashboard:
  - Disk usage per box
  - Total storage overview
  - Bulk delete files
- Download protection — files served through PHP, auth always checked
- Security headers (CSP, X-Frame-Options, HSTS, etc.)
- Brute-force protection — progressive delays, IP-based lockout

**Apache2 config:**
- VirtualHost on port 80 (SSL handled by reverse proxy, not Apache)
- `AllowOverride All` for .htaccess
- Security headers in .htaccess (CSP, HSTS, X-Frame-Options, etc.)

**Reverse proxy (Nginx Proxy Manager):**
- SSL termination with Let's Encrypt certificates
- HTTP → HTTPS redirect
- Proxies files.example.com → YOUR_SERVER_IP:80
- Custom config: `client_max_body_size 300m`, `proxy_read_timeout 600`, `proxy_request_buffering off`

**Chunk lifecycle:**
- Stale chunks cleaned up after 1 hour (cron job)
- Cancel button deletes chunks immediately via API
- Tab/browser close sends cleanup via `navigator.sendBeacon`
- Assembly timeout handled by client-side polling fallback

---

### Milestone 3 — Quality of Life
Polish and convenience features.

**Features:**
- Link sharing — temporary public links with expiry (1h to 7d), public download page, manage/delete existing links
- Clipboard paste upload — paste screenshots directly (Ctrl+V on page)
- File preview — inline thumbnails for images, Preview button for images/text/PDF in new tab
- Multi-file upload with background queue — select multiple files, per-file progress bars, pause/resume/cancel per file, file list refreshes live as each completes
- Parallel chunk uploads — 3 chunks simultaneously (50MB each = 150MB concurrent)
- Dark/light theme toggle — VS Code-style neutral grey dark mode, persisted in localStorage, respects system preference
- Storage quotas per box — admin sets limit (1GB to 100GB or unlimited), usage bar in box view, enforced on upload
- Activity log — logs uploads, downloads, deletes, logins, share actions, admin actions; filterable by action/box, paginated, color-coded badges, auto-pruned after 30 days
- Admin 2FA — TOTP setup with QR code, authenticator app verification, CLI reset script
- Admin password change from dashboard
- Disk usage overview in admin panel (total/free/used from VM filesystem)

**Deferred:**
- API key auth — scripting uploads via curl (not needed currently, can add later)

---

### Milestone 4 — Mobile-Friendly
Make all pages responsive for phones and tablets without changing the desktop layout.

**Approach:** CSS-only — media queries in `style.css`, no PHP/HTML/JS changes.

**Changes:**
- File tables convert to stacked card layout on mobile (hide thead, block display on tr/td)
- File action buttons fit in a single row on cards
- Upload controls stack vertically on phones
- Admin boxes table converts to cards with stacked controls
- Activity log hides IP and Size columns on mobile
- Stats row wraps to 2x2 grid on mobile
- Modals go full-width on mobile with scroll handling
- Login pages reduce top margin on phones
- New breakpoint at 480px for small phones
