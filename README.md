# File Dump

> This project was built entirely using [Claude Code](https://claude.ai/claude-code) — Anthropic's AI coding agent.

A self-hosted web application for temporary file sharing between devices.

## Screenshots

<details>
<summary>Light Mode</summary>

**Box Login**
![Box Login - Light](assets/screenshots/Light%20-%20Box%20Login.png)

**Box View — File List & Upload Queue**
![Box View - Light](assets/screenshots/Light%20-%20Main%20Box.png)

**Admin Dashboard — Storage & Box Management**
![Admin Dashboard - Light](assets/screenshots/Light%20-%20Admin%201.png)

**Admin Dashboard — Settings & Activity Log**
![Admin Settings - Light](assets/screenshots/Light%20-%20Admin%202.png)

</details>

<details>
<summary>Dark Mode</summary>

**Box Login**
![Box Login - Dark](assets/screenshots/Dark%20-%20Box%20Login.png)

**Box View — File List & Upload Queue**
![Box View - Dark](assets/screenshots/Dark%20-%20Main%20Box.png)

**Admin Dashboard — Storage & Box Management**
![Admin Dashboard - Dark](assets/screenshots/Dark%20-%20Admin%201.png)

**Admin Dashboard — Settings & Activity Log**
![Admin Settings - Dark](assets/screenshots/Dark%20-%20Admin%202.png)

</details> Upload files from any machine, download them from any other, and let them expire automatically. Designed as a lightweight, privacy-first alternative to cloud file-sharing services.

## Features

- **Box-based file isolation** -- organize files into password-protected containers ("boxes")
- **Chunked uploads** -- supports files up to 50GB+ via parallel 50MB chunks with resume
- **Multi-file upload queue** -- select multiple files, each with its own progress bar, pause/resume/cancel
- **Drag-and-drop** -- drop files directly onto the page to upload
- **Clipboard paste** -- paste screenshots directly (Ctrl+V)
- **File preview** -- inline thumbnails for images, preview button for images/text/PDF
- **Link sharing** -- temporary public download links with configurable expiry
- **Auto-expiry** -- set TTL per box or per file; a cron job cleans up automatically
- **Dark/light theme** -- toggle with system preference detection
- **Storage quotas** -- per-box limits with usage bars
- **Activity log** -- tracks uploads, downloads, deletions, logins with filters and pagination
- **Admin dashboard** -- manage boxes, view storage, disk usage, change passwords
- **Admin 2FA** -- TOTP-based two-factor authentication (Google/Microsoft Authenticator)
- **Security hardened** -- CSRF protection, bcrypt passwords, rate limiting, brute-force lockout, CSP/HSTS headers, prepared SQL statements

## Tech Stack

| Component | Technology |
|---|---|
| Backend | PHP 8.2 (vanilla, no frameworks) |
| Database | SQLite3 |
| Web Server | Apache 2.4 with mod_rewrite and mod_headers |
| Frontend | HTML, CSS, JavaScript (vanilla) |
| SSL | Let's Encrypt (via reverse proxy or Certbot) |

## Installation

### Requirements

- Debian 12+ (or similar Linux distribution)
- Apache 2.4+
- PHP 8.2+ with SQLite3 extension
- Root or sudo access

### Step 1: Install Dependencies

```bash
sudo apt update
sudo apt install -y apache2 php php-sqlite3 php-cli sqlite3
```

### Step 2: Enable Apache Modules

```bash
sudo a2enmod rewrite headers
```

### Step 3: Configure PHP

Edit the Apache PHP config (usually `/etc/php/8.2/apache2/php.ini`):

```ini
upload_max_filesize = 256M
post_max_size = 260M
max_execution_time = 300
```

These settings allow large file uploads. The chunked upload system handles files bigger than this by splitting them into 50MB pieces.

### Step 4: Deploy the Application

```bash
# Clone or copy files to your web root
sudo mkdir -p /var/www/html/filedump
sudo cp -r . /var/www/html/filedump/
sudo chown -R www-data:www-data /var/www/html/filedump
```

### Step 5: Configure Apache VirtualHost

Create `/etc/apache2/sites-available/filedump.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/html/filedump

    <Directory /var/www/html/filedump>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite filedump.conf
sudo a2dissite 000-default.conf  # Optional: disable default site
sudo systemctl restart apache2
```

### Step 6: Initialize the Database

```bash
sudo -u www-data php /var/www/html/filedump/setup.php
```

This creates the SQLite database, tables, and default admin/box accounts. **Delete setup.php after running it:**

```bash
sudo rm /var/www/html/filedump/setup.php
```

### Step 7: Set Up the Cleanup Cron Job

The cleanup job deletes expired files, stale upload chunks, and old activity log entries:

```bash
sudo crontab -u www-data -e
```

Add this line:

```
*/15 * * * * php /var/www/html/filedump/cron/cleanup.php >> /var/log/filedump-cleanup.log 2>&1
```

### Step 8: SSL (Recommended)

For HTTPS, you have two options:

**Option A: Reverse proxy (recommended)**
Place a reverse proxy like [Nginx Proxy Manager](https://nginxproxymanager.com/) in front of Apache. It handles SSL termination with Let's Encrypt and can serve multiple sites on the same IP.

Add these settings in the proxy's advanced config:

```
client_max_body_size 300m;
proxy_request_buffering off;
proxy_read_timeout 600;
proxy_connect_timeout 600;
proxy_send_timeout 600;
```

**Option B: Certbot directly on Apache**

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d your-domain.com
```

## Default Credentials

| Account | Username | Password |
|---|---|---|
| Admin panel | `admin` | `changeme` |
| Main box | *(none)* | `changeme` |

**Change these immediately after first login.** The admin panel is at `/admin/`.

## Configuration

Key settings in `includes/config.php`:

| Setting | Default | Description |
|---|---|---|
| `CHUNK_SIZE` | 50MB | Size of each upload chunk |
| `MAX_UPLOAD_SIZE` | 50GB | Maximum total file size |
| `SESSION_LIFETIME` | 12 hours | Box session duration |
| `ADMIN_SESSION_LIFETIME` | 2 hours | Admin session duration |
| `CHUNK_MAX_AGE` | 1 hour | Stale chunks cleaned up after this |

## Admin Features

- **2FA Setup**: Admin panel > Admin Settings > Set Up 2FA. Scan QR code with your authenticator app.
- **Reset 2FA** (if locked out): `sudo -u www-data php /var/www/html/filedump/cli/reset-2fa.php admin`
- **Storage Quotas**: Set per-box limits from the admin panel.
- **Activity Log**: View all uploads, downloads, deletions, and logins at the bottom of the admin panel.

## Project Structure

```
filedump/
├── index.php                # Box login page
├── box.php                  # Box file view (upload/download/delete)
├── setup.php                # One-time database initialization
├── share.php                # Public share link download page
├── admin/
│   ├── index.php            # Admin login (with optional 2FA)
│   └── dashboard.php        # Box management, storage, activity log
├── api/
│   ├── upload.php           # Standard file upload
│   ├── upload-chunk.php     # Chunked upload (init/chunk/complete/status/cancel)
│   ├── download.php         # Authenticated file download/preview
│   ├── delete.php           # File deletion
│   └── share.php            # Share link create/list/delete
├── includes/
│   ├── config.php           # App configuration
│   ├── db.php               # SQLite connection and schema
│   ├── auth.php             # Authentication and session management
│   ├── helpers.php          # CSRF, formatting, quotas, activity logging
│   └── totp.php             # TOTP 2FA implementation
├── assets/
│   ├── style.css            # Responsive styling with dark mode
│   ├── app.js               # Upload queue, drag-drop, paste, chunking
│   ├── share.js             # Share link modal
│   ├── confirm.js           # Delete confirmation modal
│   ├── theme.js             # Light/dark theme toggle
│   ├── totp-setup.js        # 2FA QR code renderer
│   └── qrcode.min.js        # QR code generation library
├── cli/
│   └── reset-2fa.php        # CLI tool to reset admin 2FA
├── cron/
│   └── cleanup.php          # Expired file/chunk/log cleanup
├── data/                    # Runtime data (gitignored)
│   ├── filedump.db          # SQLite database
│   ├── uploads/             # Uploaded files by box
│   └── chunks/              # Temporary chunked upload storage
└── .htaccess                # Security rules and headers
```

## Running Tests

```bash
# Unit tests (PHP CLI, no server required)
php tests/test_unit.php

# HTTP integration tests (requires running server)
bash tests/test_http.sh http://localhost

# Chunked upload tests (requires running server)
bash tests/test_chunked.sh http://localhost
```

## License

Apache License 2.0 -- see [LICENSE](LICENSE) for details.
