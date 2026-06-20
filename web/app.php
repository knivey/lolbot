<?php
// Shared deps + helpers for the web panel. All functions are prefixed web_.

/** @return array{twig:\Twig\Environment,svc:\lolbot\config\ConfigService,em:\Doctrine\ORM\EntityManager,config:array<string,mixed>,bot_base:string,bot_key:string} */
function web_app(): array
{
    // Not static-cached, so tests can vary $config / $entityManager per test.
    global $entityManager, $config;
    $twig = new \Twig\Environment(
        new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates'),
        ['strict_variables' => false, 'autoescape' => 'html'],
    );
    return [
        'twig' => $twig,
        'svc' => new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier()),
        'em' => $entityManager,
        'config' => is_array($config) ? $config : [],
        'bot_base' => web_bot_base(is_array($config) ? $config : []),
        'bot_key' => web_control_key(is_array($config) ? $config : []),
    ];
}

function web_control_key(array $config): string
{
    return isset($config['control_key']) && is_string($config['control_key']) ? $config['control_key'] : '';
}

function web_bot_base(array $config): string
{
    return isset($config['listen']) && is_string($config['listen']) ? 'http://' . $config['listen'] : '';
}

/** Open mode: no control_key configured (loopback/tunnel intended). Defined here (not auth.php)
 *  so app helpers can use it before auth.php is loaded. */
function web_is_auth_open(): bool
{
    return web_control_key(web_app()['config']) === '';
}

/** cURL to the running bot's /_control/*. Returns decoded JSON (array) or null if unreachable. */
function web_bot_http(array $app, string $method, string $path, ?array $body = null): ?array
{
    if ($app['bot_base'] === '' || $app['bot_key'] === '' || !function_exists('curl_init')) {
        return null;
    }
    $url = $app['bot_base'] . $path;
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $headers = ['key: ' . $app['bot_key']];
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
            $headers[] = 'content-type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    } catch (\Throwable $e) {
        return null;
    }
    if (!is_string($raw) || $code === 0 || $code >= 400) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

/** Fetch live bot status from the running bot, or [] if unreachable. @return list<array> */
function web_bot_status(array $app): array
{
    $res = web_bot_http($app, 'GET', '/_control/status');
    return isset($res['bots']) && is_array($res['bots']) ? $res['bots'] : [];
}

function web_is_htmx(): bool
{
    return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
}

/** Render a full page (base.twig) and exit. */
function web_render(string $template, array $vars = []): never
{
    $app = web_app();
    $base = ['active' => '', 'authed' => !web_is_auth_open(), 'section' => ''];
    echo $app['twig']->render($template, array_merge($base, $vars));
    exit;
}

/** Render a fragment (no base.twig) and exit. */
function web_render_fragment(string $template, array $vars = []): never
{
    $app = web_app();
    echo $app['twig']->render($template, $vars);
    exit;
}

/** Small error fragment for inline (HTMX) handlers, so an error never renders a full page into a fragment target. */
function web_error_fragment(string $message, int $status = 400): never
{
    http_response_code($status);
    echo '<div class="badge-bad">' . htmlspecialchars($message, ENT_QUOTES) . '</div>';
    exit;
}

function web_redirect(string $path): never
{
    if (web_is_htmx()) {
        header('HX-Redirect: ' . $path); // HTMX navigates the whole page.
        exit;
    }
    header('Location: ' . $path);
    exit;
}
