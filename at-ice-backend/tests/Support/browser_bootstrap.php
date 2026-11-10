<?php

// The browser suite must never inherit the developer's database or external services.
$root = str_replace('\\', '/', (string) getenv('ATICE_TEST_ROOT'));
if (! preg_match('~(?:^|/)atice-browser-test[^/]*$~', $root)) {
    throw new RuntimeException('ATICE_TEST_ROOT must name a dedicated atice-browser-test directory.');
}
foreach (['', '/storage/framework/cache/data', '/storage/framework/sessions', '/storage/framework/views', '/storage/logs', '/bootstrap', '/env'] as $dir) {
    if (! is_dir($root.$dir) && ! mkdir($root.$dir, 0700, true)) {
        throw new RuntimeException('Cannot create browser test runtime.');
    }
}
$root = str_replace('\\', '/', realpath($root));
$database = $root.'/browser-test.sqlite';
if (! is_file($database)) {
    touch($database);
}
if (! is_file($root.'/app-key')) {
    file_put_contents($root.'/app-key', 'base64:'.base64_encode(random_bytes(32)));
}
$settings = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => trim(file_get_contents($root.'/app-key')),
    'APP_URL' => 'http://127.0.0.1:15173', 'APP_TIMEZONE' => 'Asia/Manila', 'ATICE_TIMEZONE' => 'Asia/Manila',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'DATABASE_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4',
    'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'single',
    'LARAVEL_STORAGE_PATH' => $root.'/storage', 'VIEW_COMPILED_PATH' => $root.'/storage/framework/views',
    'APP_CONFIG_CACHE' => $root.'/bootstrap/config.php', 'APP_PACKAGES_CACHE' => $root.'/bootstrap/packages.php',
    'APP_SERVICES_CACHE' => $root.'/bootstrap/services.php', 'APP_ROUTES_CACHE' => $root.'/bootstrap/routes.php',
    'APP_EVENTS_CACHE' => $root.'/bootstrap/events.php',
    'GROQ_API_KEY' => '', 'EMAIL_VERIFY_API_KEY' => '', 'PUSHER_APP_KEY' => '', 'PUSHER_APP_SECRET' => '',
];
foreach ($settings as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
Illuminate\Database\Connection::resolverFor('sqlite', static function ($pdo, $path, $prefix, array $config) use ($database) {
    if (realpath($path) !== realpath($database)) {
        throw new RuntimeException('Browser test database isolation violated.');
    }

    return new Illuminate\Database\SQLiteConnection($pdo, $path, $prefix, $config);
});
foreach (['mysql', 'pgsql', 'sqlsrv'] as $driver) {
    Illuminate\Database\Connection::resolverFor($driver, static function () {
        throw new RuntimeException('Browser tests may only use their isolated SQLite database.');
    });
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if (preg_match('/^[A-Za-z]:/', $root)) {
    $app->addAbsoluteCachePathPrefix(substr($root, 0, 3));
}

return $app;
