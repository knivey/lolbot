<?php
// (method, path) -> handler. Section tasks append their routes before the 404 fallback.
require_once __DIR__ . '/sections/bots.php';
require_once __DIR__ . '/sections/networks.php';
require_once __DIR__ . '/sections/ignores.php';
require_once __DIR__ . '/sections/services.php';
require_once __DIR__ . '/sections/linktitles.php';

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

    if ($method === 'GET' && $path === '/bots') { web_bots_list(); }
    if ($method === 'GET' && $path === '/bots/new') { web_bots_new(); }
    if ($method === 'POST' && $path === '/bots') { web_bots_create(); }
    if ($method === 'GET' && preg_match('#^/bots/(\d+)$#', $path, $m)) { web_bots_edit((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)$#', $path, $m)) { web_bots_update((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/delete$#', $path, $m)) { web_bots_delete((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/channels$#', $path, $m)) { web_bots_add_channel((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/channels/(\d+)/delete$#', $path, $m)) { web_bots_del_channel((int)$m[1], (int)$m[2]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/(reconnect|jump|respawn)$#', $path, $m)) { web_bots_action((int)$m[1], $m[2]); }

    if ($method === 'GET' && $path === '/networks') { web_networks_list(); }
    if ($method === 'GET' && $path === '/networks/new') { web_networks_new(); }
    if ($method === 'POST' && $path === '/networks') { web_networks_create(); }
    if ($method === 'GET' && preg_match('#^/networks/(\d+)$#', $path, $m)) { web_networks_edit((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)$#', $path, $m)) { web_networks_update((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)/delete$#', $path, $m)) { web_networks_delete((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)/servers$#', $path, $m)) { web_networks_add_server((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)/servers/(\d+)/delete$#', $path, $m)) { web_networks_del_server((int)$m[1], (int)$m[2]); }

    if ($method === 'GET' && $path === '/ignores') { web_ignores_list(); }
    if ($method === 'POST' && $path === '/ignores') { web_ignores_create(); }
    if ($method === 'POST' && preg_match('#^/ignores/(\d+)/delete$#', $path, $m)) { web_ignores_delete((int)$m[1]); }

    if ($method === 'GET' && $path === '/services') { web_services(); }
    if ($method === 'POST' && $path === '/services/ai') { web_services_save('ai'); }
    if ($method === 'POST' && $path === '/services/paste') { web_services_save('paste'); }

    if ($method === 'GET' && $path === '/linktitles') { web_linktitles(); }
    if ($method === 'POST' && preg_match('#^/linktitles/(\d+)$#', $path, $m)) { web_linktitles_save((int)$m[1]); }

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
