<?php
// Solo para desarrollo local:  php -S localhost:8080 -t public cli/dev_router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = dirname(__DIR__) . '/public' . $path;
if ($path !== '/' && is_file($file)) return false;
if (str_starts_with($path, '/admin')) { require dirname(__DIR__) . '/public/admin/index.php'; return true; }
if ($path === '/webhook.php') { require dirname(__DIR__) . '/public/webhook.php'; return true; }
require dirname(__DIR__) . '/public/index.php';
