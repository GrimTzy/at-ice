<?php

define('LARAVEL_START', microtime(true));
$app = require __DIR__.'/browser_bootstrap.php';
$app->handleRequest(Illuminate\Http\Request::capture());
