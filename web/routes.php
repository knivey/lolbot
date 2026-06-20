<?php
// (method, path) -> handler. Section tasks append their routes before the 404 fallback.
function web_dispatch(string $method, string $path): void
{
    // Static assets (containment-checked — assets are served before the auth gate).
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

    // Auth routes are always reachable.
    if ($method === 'GET' && $path === '/login') { web_login_form(); }
    if ($method === 'POST' && $path === '/login') { web_login_submit(); }
    if ($method === 'GET' && $path === '/logout') {
        $_SESSION = []; session_destroy();
        web_redirect('/login');
    }

    // Everything else requires auth (no-op when open).
    web_require_auth();

    if ($method === 'GET' && ($path === '/' || $path === '')) { web_home(); }

    http_response_code(404);
    echo "Not found";
}

function web_home(): never
{
    web_render('home.twig', ['active' => 'overview', 'authed' => !web_is_auth_open(), 'section' => 'Overview']);
}

function web_login_form(?string $error = null): never
{
    echo web_app()['twig']->render('login.twig', ['error' => $error]);
    exit;
}

function web_login_submit(): never
{
    $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
    if (web_attempt_login($key)) {
        web_redirect('/');
    }
    web_login_form('Invalid key');
}
