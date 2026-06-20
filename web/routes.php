<?php
// (method, path) -> handler. Section tasks append their routes before the 404 fallback.
function web_dispatch(string $method, string $path): void
{
    // Static assets served directly (php-fpm serves these via nginx; php-S needs this).
    // Containment check prevents path-traversal (assets are served before the auth gate).
    if (str_starts_with($path, '/assets/')) {
        $real = realpath(__DIR__ . $path);
        $root = realpath(__DIR__ . '/assets');
        if ($real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) {
            $ext = pathinfo($real, PATHINFO_EXTENSION);
            header('content-type: ' . match ($ext) { 'js' => 'text/javascript', 'css' => 'text/css', default => 'application/octet-stream' });
            readfile($real);
            exit;
        }
    }

    // Home (auth gate applied inside web_home once auth.php is added in Task 3).
    if ($method === 'GET' && ($path === '/' || $path === '')) {
        web_home();
    }

    http_response_code(404);
    echo "Not found";
}

function web_home(): never
{
    web_render('home.twig', ['active' => '', 'authed' => false, 'section' => 'Home']);
}
