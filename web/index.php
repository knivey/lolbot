<?php
// Front controller — runs identically under php-fpm (nginx) and `php -S`.
// chdir to the repo root so config.yaml's relative DB path resolves the same way
// the bot resolves it, regardless of how this entry is invoked (php -S from any
// directory, or nginx + php-fpm whose CWD is otherwise undefined).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/auth.php';

// Reverse-proxy aware secure cookie.
$proto = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (($_SERVER['HTTPS'] ?? '') === 'on'))
    ? 'https'
    : 'http';
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'httponly' => true, 'samesite' => 'Lax',
    'secure' => $proto === 'https',
]);
session_start();

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$rawUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($rawUri, PHP_URL_PATH) ?: '/';
// Normalise trailing slash (except root).
if ($path !== '/' && substr($path, -1) === '/') $path = rtrim($path, '/');

require __DIR__ . '/routes.php';
web_dispatch($method, $path);
