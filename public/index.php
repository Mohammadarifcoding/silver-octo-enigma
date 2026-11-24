<?php
declare(strict_types=1);
require __DIR__ . '/../server/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', '0');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    if (str_starts_with($path, '/admin') || str_starts_with($path, '/api/')) header('Cache-Control: no-store');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) json_response(['error' => 'Request is too large.'], 413);
    if ($method === 'POST') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $originParts = $origin ? parse_url($origin) : null;
        $originHost = is_array($originParts) ? ($originParts['host'] ?? '') . (isset($originParts['port']) ? ':' . $originParts['port'] : '') : '';
        if (($origin && $originHost !== ($_SERVER['HTTP_HOST'] ?? '')) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            json_response(['error' => 'Request origin is not allowed.'], 403);
        }
    }
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        require ROOT . '/server/admin.php';
        exit;
    }
    if ($path === '/api/contact' && $method === 'POST') {
        if (rate_limited('contact', 10, 600)) {
            header('Retry-After: 600');
            json_response(['error' => 'Too many messages. Please try again in 10 minutes.'], 429);
        }
        $input = file_get_contents('php://input', false, null, 0, 32769);
        if (strlen($input) > 32768) json_response(['error' => 'Request is too large.'], 413);
        if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            try { $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException) { json_response(['error' => 'Invalid request.'], 400); }
        } else $data = $_POST;
        if (!is_array($data)) json_response(['error' => 'Invalid request.'], 400);
        $name = text_value($data, 'name'); $email = text_value($data, 'email');
        $phone = text_value($data, 'phone'); $topic = text_value($data, 'topic'); $message = text_value($data, 'message');
        if (text_length($name) < 3 || text_length($name) > 120 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($phone) > 30 || ($phone !== '' && !preg_match('/^(\+?88)?01[3-9]\d{8}$/', preg_replace('/[\s-]/', '', $phone)))
            || !in_array($topic, TOPICS, true) || text_length($message) < 20 || text_length($message) > 5000) {
            json_response(['error' => 'Please check all fields. Use a name of 3–120 characters and a message of 20–5,000 characters.'], 422);
        }
        database()->prepare('INSERT INTO messages (name, email, phone, topic, message) VALUES (?, ?, ?, ?, ?)')->execute([$name, $email, $phone, $topic, $message]);
        json_response(['ok' => true], 201);
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD'); http_response_code(405); echo 'Method not allowed.'; exit;
    }
    $pages = ['index.html', 'about.html', 'admission.html', 'notices.html', 'contact.html', 'departments.html'];
    $file = $path === '/' ? 'index.html' : ltrim($path, '/');
    if (in_array($file, $pages, true)) {
        require ROOT . '/server/views.php';
        echo public_page(file_get_contents(ROOT . '/' . $file), $file);
        exit;
    }
    $asset = realpath(ROOT . '/' . $file);
    $assetRoot = realpath(ROOT . '/assets') . DIRECTORY_SEPARATOR;
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
    $extension = pathinfo($asset ?: '', PATHINFO_EXTENSION);
    if ($asset && str_starts_with($asset, $assetRoot) && is_file($asset) && isset($types[$extension])) {
        header('Content-Type: ' . $types[$extension]);
        header('Cache-Control: public, max-age=3600');
        if ($method !== 'HEAD') readfile($asset);
        exit;
    }
    http_response_code(404); echo 'Page not found.';
} catch (Throwable $error) {
    error_log((string)$error);
    http_response_code(500);
    if (str_starts_with($path, '/api/')) json_response(['error' => 'Unable to complete your request. Please try again.'], 500);
    echo 'Unable to load this page. Please check the server configuration or try again later.';
}
