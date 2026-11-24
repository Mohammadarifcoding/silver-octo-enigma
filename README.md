# Khulna Polytechnic Institute website

A plain PHP and SQLite backend with the existing HTML, CSS and JavaScript frontend. No Node.js, Composer packages or separate database service are required.

## Run locally

Requires PHP 8.2+ with `pdo_sqlite` enabled. Check with `php -m`.

The included `.env` contains public presentation credentials:

- Username: `admin`
- Password: `CollegeDemo2026!`

On Windows, double-click **start.bat**. Or run this from the project folder:

   ```powershell
   php run.php
   ```

Open http://localhost:8080. Sign in at http://localhost:8080/admin. Keep the terminal open while presenting. Press Ctrl+C to stop. If port 8080 is busy, run `php run.php 8081` and use port 8081 in the URLs.

The database is created and the nine departments are loaded automatically if `data/college.sqlite` is missing. The downloadable ZIP includes an already initialised copy of this database. No SQL import or separate database server is needed. Once an account exists, change its password through **Account & security**; changing `.env` does not reset an existing account.

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

`data/college.sqlite` stores departments, messages, admin accounts and rate limits. `data/sessions` stores private PHP sessions. Runtime data is excluded from Git; the presentation `.env` is included.

Existing SQLite messages and departments are preserved. On first PHP use, the app adds message statuses and authentication tables. A fresh database imports the nine departments from `server/departments.json`. Seed data never overwrites existing departments.

Back up `data` with the server stopped. Do not delete it to reset credentials. Optionally set `SQLITE_PATH` and `SESSION_PATH` in `.env` to private writable locations outside the project.

## Production hosting

Set the web server's document root to the **public** directory. Everything else, including `.env`, the SQLite file, PHP application files and HTML templates, stays outside the public document root. Do not serve the project root.

Apache: enable `mod_rewrite` and allow the supplied `public/.htaccess`. Nginx: route requests to `public/index.php` using your normal PHP-FPM configuration. Static assets are served through the same PHP entry point.

Replace the public demo password before public hosting. Use HTTPS and set `COOKIE_SECURE=true`. Use a persistent writable disk for the database and sessions. Keep PHP error display off and inspect server error logs for failures. The built-in PHP server is for local development only.

## Downloadable copy

`downloads/college-website.zip` contains the website code, assets, screenshots, `.env`, launchers, and a consistent snapshot of `data/college.sqlite`. It excludes Git metadata, running sessions, logs and temporary SQLite files. Extract the entire ZIP before running **start.bat**. GitHub is not needed to run this copy.

To rebuild this package after making changes, start the website once to initialise its database, then run `python scripts/package.py`.

## Verification

Run `python tests/integration.py` (Python standard library only) to launch PHP with a separate temporary database. Tests cover legacy data migration, contact validation, protected pages, CSRF, session rotation and expiry, logout, password changes and session revocation, department edits, escaping, private file protection, persistent rate limits, inbox pagination, and all local page links, anchors and assets.

Check PHP syntax with `php -l path/to/file.php`.

Authentication follows the [PHP session security guidance](https://www.php.net/manual/en/session.security.ini.php) and [session management documentation](https://www.php.net/manual/en/features.session.security.management.php).
