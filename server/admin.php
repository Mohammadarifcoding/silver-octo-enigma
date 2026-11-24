<?php
declare(strict_types=1);
require __DIR__ . '/views.php';
start_admin_session();
$db = database();
$admin = current_admin();
$routes = ['/admin', '/admin/', '/admin/login', '/admin/logout', '/admin/messages', '/admin/departments', '/admin/account'];
if (!in_array($path, $routes, true)) { http_response_code(404); echo 'Page not found.'; return; }
$page = match ($path) { '/admin/messages' => 'messages', '/admin/departments' => 'departments', '/admin/account' => 'account', default => 'overview' };
$error = '';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($method === 'POST') {
    if (!valid_csrf()) { http_response_code(403); echo 'This form has expired. Reload the page and try again.'; return; }
    if ($path === '/admin/login') {
        if (rate_limited('login', 8, 900)) {
            http_response_code(429); header('Retry-After: 900');
            $error = 'Too many sign-in attempts. Please try again in 15 minutes.';
        } else {
            $username = text_value($_POST, 'username');
            $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            $query = $db->prepare('SELECT * FROM admins WHERE username = ?');
            $query->execute([$username]);
            $candidate = $query->fetch();
            // Always verify a hash so unknown usernames use the same expensive check.
            $hash = $candidate['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $verified = strlen($password) <= 72 && password_verify($password, $hash);
            if ($candidate && $verified) {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $candidate['id']]);
                session_regenerate_id(true);
                $_SESSION = ['admin_id' => (int)$candidate['id'], 'auth_version' => (int)$candidate['auth_version'], 'signed_in' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
                redirect('/admin');
            }
            http_response_code(401); $error = 'Incorrect username or password.';
        }
    } elseif (!$admin) redirect('/admin/login');
    elseif ($path === '/admin/logout') {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
        redirect('/admin/login');
    } elseif ($path === '/admin/messages') {
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = text_value($_POST, 'status');
        if (!$id || !in_array($status, ['unread', 'read', 'archived'], true)) { http_response_code(422); $error = 'Choose a valid message and status.'; }
        else {
            $query = $db->prepare('UPDATE messages SET status = ? WHERE id = ?');
            $query->execute([$status, $id]);
            if (!$query->rowCount()) { http_response_code(404); $error = 'Message not found.'; }
            else { $_SESSION['flash'] = 'Message status updated.'; redirect('/admin/messages?view=' . $id); }
        }
    } elseif ($path === '/admin/account') {
        if (rate_limited('password-change:' . $admin['id'], 8, 900)) { http_response_code(429); $error = 'Too many attempts. Try again in 15 minutes.'; }
        else {
            $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
            $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
            $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
            if (strlen($current) > 72 || !password_verify($current, $admin['password_hash'])) $error = 'Your current password is incorrect.';
            elseif (strlen($new) < 12 || strlen($new) > 72) $error = 'Use a new password of 12–72 bytes.';
            elseif ($new !== $confirm) $error = 'The new passwords do not match.';
            elseif ($new === $current) $error = 'Choose a different password.';
            else {
                $db->prepare('UPDATE admins SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
                $_SESSION['auth_version'] = (int)$admin['auth_version'] + 1;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                session_regenerate_id(true);
                $_SESSION['flash'] = 'Password changed. All other admin sessions have been signed out.';
                redirect('/admin/account');
            }
            if ($error) http_response_code(422);
        }
    }
} elseif (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); return; }

if (!$admin) {
    if ($path !== '/admin/login') redirect('/admin/login');
    require __DIR__ . '/templates/login.php';
    return;
}
if ($path === '/admin/login' || $path === '/admin/' || ($method === 'GET' && $path === '/admin/logout')) redirect('/admin');

$departments = $db->query('SELECT * FROM departments ORDER BY position')->fetchAll();
$editing = null;
if ($page === 'departments') {
    $editId = $method === 'POST' ? text_value($_POST, 'id') : text_value($_GET, 'edit');
    foreach ($departments as $d) if ($d['id'] === $editId) $editing = $d;
    if ($method === 'POST' && $editId === '') { http_response_code(422); $error = 'Choose a department to update.'; }
    if ($editId && !$editing) { http_response_code(404); $error = 'Department not found.'; }
    if ($method === 'POST' && $editing) {
        foreach (['name', 'name_bn', 'code', 'category', 'description', 'careers'] as $key) $editing[$key] = text_value($_POST, $key);
        $labsInput = text_value($_POST, 'labs');
        $labs = array_values(array_filter(array_map('trim', explode("\n", $labsInput)), fn($s) => $s !== ''));
        $editing['seats'] = text_value($_POST, 'seats');
        $seats = filter_var($editing['seats'], FILTER_VALIDATE_INT);
        $editing['labs'] = json_encode($labs, JSON_UNESCAPED_UNICODE);
        if (text_length($editing['name']) < 3 || text_length($editing['name']) > 150 || text_length($editing['name_bn']) > 150
            || !preg_match('/^[A-Za-z0-9-]{2,12}$/', $editing['code']) || !isset(CATEGORIES[$editing['category']])
            || text_length($editing['description']) < 20 || text_length($editing['description']) > 2000
            || text_length($editing['careers']) > 500 || count($labs) > 12 || strlen($labsInput) > 1500
            || $seats === false || $seats < 0 || $seats > 10000 || $seats % 2 !== 0) {
            http_response_code(422); $error = 'Check the department fields. Use a description of 20–2,000 characters and an even number of seats from 0 to 10,000.';
        } else {
            $duplicate = $db->prepare('SELECT 1 FROM departments WHERE code = ? COLLATE NOCASE AND id != ?');
            $duplicate->execute([$editing['code'], $editing['id']]);
            if ($duplicate->fetchColumn()) { http_response_code(422); $error = 'Another department already uses this code.'; }
            else {
                $db->prepare('UPDATE departments SET name=?, name_bn=?, code=?, category=?, description=?, labs=?, careers=?, seats=? WHERE id=?')->execute([
                    $editing['name'], $editing['name_bn'], $editing['code'], $editing['category'], $editing['description'], $editing['labs'], $editing['careers'], $seats, $editing['id']]);
                $_SESSION['flash'] = 'Department updated. Changes are now visible on the website.';
                redirect('/admin/departments');
            }
        }
    }
}
$counts = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(status='unread'),0) AS unread, COALESCE(SUM(status='archived'),0) AS archived FROM messages")->fetch();
$recent = $db->query('SELECT * FROM messages ORDER BY id DESC LIMIT 5')->fetchAll();
$messages = []; $selected = null; $messageCount = 0; $totalPages = 1; $number = 1;
$queryText = substr(text_value($_GET, 'q'), 0, 200);
$filter = text_value($_GET, 'status');
if (!in_array($filter, ['unread', 'read', 'archived'], true)) $filter = '';
if ($page === 'messages') {
    $where = []; $params = [];
    if ($filter) { $where[] = 'status = ?'; $params[] = $filter; }
    if ($queryText !== '') { $where[] = '(name LIKE ? OR email LIKE ? OR topic LIKE ? OR message LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $queryText . '%')); }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $query = $db->prepare('SELECT COUNT(*) FROM messages' . $clause); $query->execute($params); $messageCount = (int)$query->fetchColumn();
    $totalPages = max(1, (int)ceil($messageCount / 20));
    $number = min($totalPages, max(1, (int)text_value($_GET, 'page')));
    $query = $db->prepare('SELECT * FROM messages' . $clause . ' ORDER BY id DESC LIMIT 20 OFFSET ' . (($number - 1) * 20));
    $query->execute($params); $messages = $query->fetchAll();
    $viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($viewId) { $query = $db->prepare('SELECT * FROM messages WHERE id = ?'); $query->execute([$viewId]); $selected = $query->fetch() ?: null; }
    else $selected = $messages[0] ?? null;
    if ($viewId && !$selected) { http_response_code(404); $error = 'Message not found.'; }
}
$titles = ['overview' => ['Overview', 'A quick look at your college website.'], 'messages' => ['Contact inbox', 'Read enquiries and keep track of what needs attention.'], 'departments' => ['Departments', 'Manage programme information shown on the website.'], 'account' => ['Account & security', 'Keep your admin account secure.']];
require __DIR__ . '/templates/dashboard.php';
