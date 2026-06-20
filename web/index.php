<?php
// Front controller — runs identically under php-fpm (nginx) and `php -S`.
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/app.php';

// Reverse-proxy aware secure cookie.
$proto = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'httponly' => true, 'samesite' => 'Lax',
    'secure' => $proto === 'https',
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Normalise trailing slash (except root).
if ($path !== '/' && substr($path, -1) === '/') $path = rtrim($path, '/');

require __DIR__ . '/routes.php';
web_dispatch($method, $path);
