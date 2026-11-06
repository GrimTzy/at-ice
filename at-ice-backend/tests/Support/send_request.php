<?php

// Test helper for ConcurrentWritesMysqlTest: boots the app and sends ONE request
// through the HTTP kernel at a given start time, so several copies run at the same
// instant as separate processes with their own database connections.
//
//   php tests/Support/send_request.php METHOD uri 'json-body' bearer kiosk-token startEpochMs
//
// Prints "<status> <short body>". The uri has no leading slash (Git Bash rewrites it).

chdir(dirname(__DIR__, 2));
require 'vendor/autoload.php';
[$_, $method, $uri, $payload, $bearer, $kiosk, $startMs] = array_pad($argv, 7, '');

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

while ((int) (microtime(true) * 1000) < (int) $startMs) {
    usleep(200);
}

$request = Illuminate\Http\Request::create('/'.ltrim($uri, '/'), $method, json_decode($payload, true) ?: [], [], [], ['HTTP_ACCEPT' => 'application/json']);
if ($bearer !== '') {
    $request->headers->set('Authorization', 'Bearer '.$bearer);
}
if ($kiosk !== '') {
    $request->headers->set('X-Kiosk-Token', $kiosk);
}

$response = $kernel->handle($request);
echo $response->getStatusCode().' '.substr(preg_replace('/\s+/', ' ', $response->getContent()), 0, 120)."\n";
