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
    $twig->addFunction(new \Twig\TwigFunction('csrf', 'web_twig_csrf'));
    return [
        'twig' => $twig,
        'svc' => new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier()),
        'em' => $entityManager,
        'config' => is_array($config) ? $config : [],
        'bot_base' => web_bot_base(is_array($config) ? $config : []),
        'bot_key' => web_control_key(is_array($config) ? $config : []),
    ];
}

/** @param array<string,mixed> $config */
function web_control_key(array $config): string
{
    return isset($config['control_key']) && is_string($config['control_key']) ? $config['control_key'] : '';
}

/** @param array<string,mixed> $config */
function web_bot_base(array $config): string
{
    return isset($config['listen']) && is_string($config['listen']) ? 'http://' . $config['listen'] : '';
}

/** cURL to the running bot's /_control/*. Returns decoded JSON (array) or null if unreachable.
 * @param array<string,mixed> $app
 * @param non-empty-string $method
 * @param array<string,mixed>|null $body
 * @return array<mixed,mixed>|null */
function web_bot_http(array $app, string $method, string $path, ?array $body = null): ?array
{
    $botBase = is_string($app['bot_base'] ?? null) ? $app['bot_base'] : '';
    $botKey = is_string($app['bot_key'] ?? null) ? $app['bot_key'] : '';
    if ($botBase === '' || $botKey === '' || !function_exists('curl_init')) {
        return null;
    }
    $url = $botBase . $path;
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $headers = ['key: ' . $botKey];
        if ($body !== null) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($jsonBody === false) return null;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
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

/** cURL to the bot; returns the HTTP status code (0 if unreachable / no key configured).
 *  For the non-JSON control endpoints (reconnect/jump/respawn) whose body is plain text.
 * @param array<string,mixed> $app
 * @param non-empty-string $method
 */
function web_bot_http_status(array $app, string $method, string $path): int
{
    $botBase = is_string($app['bot_base'] ?? null) ? $app['bot_base'] : '';
    $botKey = is_string($app['bot_key'] ?? null) ? $app['bot_key'] : '';
    if ($botBase === '' || $botKey === '' || !function_exists('curl_init')) {
        return 0;
    }
    try {
        $ch = curl_init($botBase . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['key: ' . $botKey]);
        curl_exec($ch);
        return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Fetch live bot status from the running bot, or [] if unreachable.
 * @param array<string,mixed> $app
 * @return array<mixed,mixed> */
function web_bot_status(array $app): array
{
    $res = web_bot_http($app, 'GET', '/_control/status');
    return isset($res['bots']) && is_array($res['bots']) ? $res['bots'] : [];
}

function web_is_htmx(): bool
{
    return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
}

/** Render a full page (base.twig) and exit.
 * @param array<string,mixed> $vars */
function web_render(string $template, array $vars = []): never
{
    $app = web_app();
    $base = ['active' => '', 'authed' => ($app['bot_key'] !== ''), 'section' => ''];
    echo $app['twig']->render($template, array_merge($base, $vars));
    exit;
}

/** Render a fragment (no base.twig) and exit.
 * @param array<string,mixed> $vars */
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
    echo '<div class="alert alert-danger py-2 mb-0">' . htmlspecialchars($message, ENT_QUOTES) . '</div>';
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
