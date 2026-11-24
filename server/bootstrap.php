<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const TOPICS = ['Admission', 'Examination & results', 'Certificates & documents', 'Suggestion', 'Complaint'];
const CATEGORIES = ['build' => 'Build & make', 'power' => 'Power & electrical', 'digital' => 'Digital & electronics', 'process' => 'Process & environment'];

function config(string $key, string $default = ''): string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        foreach (is_file(ROOT . '/.env') ? file(ROOT . '/.env', FILE_IGNORE_NEW_LINES) : [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$name, $value] = explode('=', $line, 2);
            $file[trim($name)] = trim(trim($value), "\"'");
        }
    }
    $value = getenv($key);
    return $value !== false ? $value : ($file[$key] ?? $default);
}

function database(): PDO
{
    static $db = null;
    if ($db) return $db;
    $path = config('SQLITE_PATH', ROOT . '/data/college.sqlite');
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) $path = ROOT . '/' . $path;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL');
    $db->exec("CREATE TABLE IF NOT EXISTS departments (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, name_bn TEXT NOT NULL, code TEXT NOT NULL,
        category TEXT NOT NULL, description TEXT NOT NULL, image TEXT NOT NULL, labs TEXT NOT NULL,
        careers TEXT NOT NULL, seats INTEGER NOT NULL, position INTEGER NOT NULL
    ); CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL,
        phone TEXT NOT NULL DEFAULT '', topic TEXT NOT NULL, message TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')), status TEXT NOT NULL DEFAULT 'unread'
    ); CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL, auth_version INTEGER NOT NULL DEFAULT 1
    ); CREATE TABLE IF NOT EXISTS request_limits (
        bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL
    )");
    // Upgrade the previous SQLite database without removing any messages or departments.
    $columns = array_column($db->query('PRAGMA table_info(messages)')->fetchAll(), 'name');
    if (!in_array('status', $columns, true)) $db->exec("ALTER TABLE messages ADD COLUMN status TEXT NOT NULL DEFAULT 'unread'");
    $db->exec('CREATE INDEX IF NOT EXISTS messages_status_id ON messages(status, id)');
    $db->beginTransaction();
    try {
        if (!$db->query('SELECT 1 FROM departments LIMIT 1')->fetchColumn()) {
            $insert = $db->prepare('INSERT INTO departments (id, name, name_bn, code, category, description, image, labs, careers, seats, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach (json_decode(file_get_contents(__DIR__ . '/departments.json'), true, 512, JSON_THROW_ON_ERROR) as $d) {
                $insert->execute([$d['id'], $d['name'], $d['name_bn'], $d['code'], $d['category'], $d['description'], $d['image'], json_encode($d['labs'], JSON_UNESCAPED_UNICODE), $d['careers'], $d['seats'], $d['position']]);
            }
        }
        if (!$db->query('SELECT 1 FROM admins LIMIT 1')->fetchColumn()) {
            $password = config('ADMIN_PASSWORD');
            $username = config('ADMIN_USERNAME', 'admin');
            if (strlen($password) < 12 || strlen($password) > 72 || $password === 'replace-with-a-long-private-password' || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
                throw new RuntimeException('Configure ADMIN_USERNAME and a private ADMIN_PASSWORD of 12–72 bytes in .env before first startup.');
            }
            $db->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return $db;
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function text_value(array $data, string $key): string { return isset($data[$key]) && is_string($data[$key]) ? trim($data[$key]) : ''; }
function text_length(string $value): int { return preg_match_all('/./us', $value) ?: 0; }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function json_response(array $data, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function rate_limited(string $action, int $max, int $seconds): bool
{
    $db = database();
    $now = time();
    $db->prepare('DELETE FROM request_limits WHERE expires < ?')->execute([$now]);
    $query = $db->prepare('INSERT INTO request_limits (bucket, hits, expires) VALUES (?, 1, ?)
        ON CONFLICT(bucket) DO UPDATE SET hits = hits + 1 RETURNING hits');
    $query->execute([hash('sha256', $action . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), $now + $seconds]);
    return (int)$query->fetchColumn() > $max;
}

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $path = config('SESSION_PATH', dirname(config('SQLITE_PATH', ROOT . '/data/college.sqlite')) . '/sessions');
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) $path = ROOT . '/' . $path;
    if (!is_dir($path)) mkdir($path, 0700, true);
    session_save_path($path);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('kpi_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'httponly' => true,
        'secure' => config('COOKIE_SECURE') === 'true' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'samesite' => 'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function valid_csrf(): bool { return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }
function current_admin(): ?array
{
    if (!isset($_SESSION['admin_id'])) return null;
    $query = database()->prepare('SELECT * FROM admins WHERE id = ?');
    $query->execute([$_SESSION['admin_id']]);
    $admin = $query->fetch();
    if (!$admin || (int)$admin['auth_version'] !== ($_SESSION['auth_version'] ?? 0) || time() - ($_SESSION['last_seen'] ?? 0) > 1800 || time() - ($_SESSION['signed_in'] ?? 0) > 28800) {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'flash' => 'Your session ended. Please sign in again.'];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['last_seen'] = time();
    return $admin;
}
