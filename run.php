<?php
declare(strict_types=1);

// Run from any working directory: php path/to/run.php [port]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80200) { fwrite(STDERR, "PHP 8.2 or newer is required.\n"); exit(1); }
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Enable extension=pdo_sqlite in your php.ini, then try again. Use php --ini to find that file.\n");
    exit(1);
}
$port = filter_var($argv[1] ?? '8080', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
if ($port === false) { fwrite(STDERR, "Choose a port between 1024 and 65535. Example: php run.php 8081\n"); exit(1); }

require __DIR__ . '/server/bootstrap.php';
try {
    if (!is_file(__DIR__ . '/.env')) throw new RuntimeException('The .env file is missing. Copy .env.example to .env and set the login details.');
    database(); // Creates missing tables and seeds departments without overwriting existing data.
} catch (Throwable $error) {
    fwrite(STDERR, 'Startup failed: ' . $error->getMessage() . "\n");
    exit(1);
}

echo "Website: http://localhost:$port\nAdmin:   http://localhost:$port/admin\nLogin details are in .env. Keep this window open; press Ctrl+C to stop.\n\n";
$command = [PHP_BINARY];
if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
array_push($command, '-S', "localhost:$port", '-t', __DIR__ . '/public', __DIR__ . '/public/index.php');
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, __DIR__);
if (!is_resource($process)) { fwrite(STDERR, "Unable to start the PHP server.\n"); exit(1); }
exit(proc_close($process));
