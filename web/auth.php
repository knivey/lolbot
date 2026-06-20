<?php
// Session-based auth + CSRF. Auth is OPEN when control_key is unset (loopback intended).
// (web_is_auth_open() lives in app.php.)

function web_is_authed(): bool
{
    return web_is_auth_open() || ($_SESSION['authed'] ?? false) === true;
}

/** Returns true on success. Uses hash_equals (timing-safe). */
function web_attempt_login(string $key): bool
{
    $real = web_control_key(web_app()['config']);
    if ($real !== '' && hash_equals($real, $key)) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $_SESSION['csrf']   = bin2hex(random_bytes(16));
        return true;
    }
    return false;
}

function web_require_auth(): void
{
    if (!web_is_authed()) {
        web_redirect('/login');
    }
}

function web_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return is_string($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
}

/** Called on every POST. Throws on mismatch (handlers catch -> error fragment). */
function web_verify_csrf(): void
{
    $tok = is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : '';
    if (web_is_auth_open()) {
        return; // open mode: no session token exists yet
    }
    $expected = is_string($_SESSION['csrf'] ?? null) ? $_SESSION['csrf'] : '';
    if ($expected === '' || !hash_equals($expected, $tok)) {
        throw new \RuntimeException('Invalid CSRF token');
    }
}

/** Twig-exposed csrf() used by the _csrf macro. */
function web_twig_csrf(): string
{
    return web_is_auth_open() ? '' : web_csrf_token();
}
