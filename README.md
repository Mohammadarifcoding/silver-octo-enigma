# Khulna Polytechnic Institute website

A plain PHP and SQLite backend with the existing HTML, CSS and JavaScript frontend. No Node.js, Composer packages or separate database service are required.

## Run locally

Requires PHP 8.2+ with `pdo_sqlite` enabled. Check with `php -m`.

1. Copy `.env.example` to `.env` if it does not exist.
2. Set `ADMIN_USERNAME` (defaults to `admin`) and a private `ADMIN_PASSWORD` of 12–72 bytes.
3. From the project folder, run:

   ```powershell
   php -S localhost:8080 -t public public/index.php
   ```

4. Open http://localhost:8080. Sign in at http://localhost:8080/admin.

A local `.env` was already created during development. Its existing password is retained when switching from Node.js. The first PHP startup creates the admin account and stores a password hash in SQLite. Once created, change its password through **Account & security**; changing `.env` does not reset an existing account.

On Windows, enable `extension=pdo_sqlite` in the loaded `php.ini` and make sure `extension_dir` points to PHP's `ext` folder. `php --ini` shows the loaded configuration. PHP has been installed through Scoop and SQLite enabled on this laptop. Open a fresh terminal if its environment predates the installation.

## Dashboard

- **Overview:** unread and total enquiries, department count, yearly intake, and recent messages.
- **Contact inbox:** search by sender, email, topic or message. Filter unread, read and archived messages. Open full messages and change their status. Results are paginated, 20 per page.
- **Departments:** edit the existing departments' English/Bangla names, code, category, description, labs, career opportunities and yearly seats. Names and seats update on the homepage; navigation and the full department page also read from SQLite. Department IDs and images stay fixed.
- **Account & security:** change the password using the current password. All other sessions are signed out.

Messages are saved locally; they are not emailed. No teacher records or permanent deletion controls are included.

## Authentication

Passwords use PHP's `password_hash` / `password_verify`. Sessions use strict mode and HttpOnly, SameSite cookies, with a new session ID after login and password changes. Sessions expire after 30 minutes of inactivity or eight hours in total. Logout is a CSRF-protected POST and destroys the server session and browser cookie. All admin forms, including login, check CSRF tokens. Login and password-change attempts are limited to eight per 15 minutes per source address; contact submissions are limited to ten per ten minutes. Limits persist in SQLite across restarts.

## Storage and migration

`data/college.sqlite` stores departments, messages, admin accounts and rate limits. `data/sessions` stores private PHP sessions. Both are excluded from Git along with `.env`.

Existing SQLite messages and departments are preserved. On first PHP use, the app adds message statuses and authentication tables. A fresh database imports the nine departments from `server/departments.json`. Seed data never overwrites existing departments.

Back up `data` with the server stopped. Do not delete it to reset credentials. Optionally set `SQLITE_PATH` and `SESSION_PATH` in `.env` to private writable locations outside the project.

## Production hosting

Set the web server's document root to the **public** directory. Everything else, including `.env`, the SQLite file, PHP application files and HTML templates, stays outside the public document root. Do not serve the project root.

Apache: enable `mod_rewrite` and allow the supplied `public/.htaccess`. Nginx: route requests to `public/index.php` using your normal PHP-FPM configuration. Static assets are served through the same PHP entry point.

Use HTTPS and set `COOKIE_SECURE=true`. Use a persistent writable disk for the database and sessions. Keep PHP error display off and inspect server error logs for failures. The built-in PHP server is for local development only.

## Verification

Run `python tests/integration.py` (Python standard library only) to launch PHP with a separate temporary database. Tests cover legacy data migration, contact validation, protected pages, CSRF, session rotation and expiry, logout, password changes and session revocation, department edits, escaping, private file protection, persistent rate limits, inbox pagination, and all local page links, anchors and assets.

Check PHP syntax with `php -l path/to/file.php`.

Authentication follows the [PHP session security guidance](https://www.php.net/manual/en/session.security.ini.php) and [session management documentation](https://www.php.net/manual/en/features.session.security.management.php).
