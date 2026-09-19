# REST Server Log File — Design

Date: 2026-09-19

## Problem

The channel bot's REST server (config `listen`: `/_control/*`, notifier
routes, `POST /aidesc`) logs only to stdout via the shared control logger,
and individual requests are not logged at all — a bad-key 403 or a 404
leaves no trace. We want a dedicated log file with a per-request access log
plus the existing control-logger output.

## Decisions (from brainstorming)

- File contains **access log + control output**: every request gets a line,
  and the control logger (Amp server internals, aidesc detail lines) also
  writes to the file. stdout logging is unchanged.
- **Daily rotation** via Monolog `RotatingFileHandler`, keep N days.
- Settings are **config.yaml keys with code defaults**; logging is on by
  default, `restlog_path: ''` disables.
- Scope: **channel bot only** (artbots.php's REST server is out of scope).

## Config keys (documented in config.example.yaml)

| Key | Default | Meaning |
|---|---|---|
| `restlog_path` | `logs/rest.log` | Base name; handler writes `logs/rest-YYYY-MM-DD.log`. Empty string disables the file handlers. |
| `restlog_days` | `14` | Daily files kept. |
| `restlog_level` | `INFO` | File handler level (PSR names: DEBUG/INFO/NOTICE/WARNING/ERROR). Parsed with Monolog v2 `Logger::toMonologLevel()`, which throws on invalid names. |

## Wiring (lolbot.php, where the control logger is built, ~line 157)

```php
$logger = new Logger("control");
$logger->pushHandler($logHandler);                       // stdout, unchanged
$restLogPath = trim((string)($config['restlog_path'] ?? 'logs/rest.log'));
if ($restLogPath !== '') {
    $dir = dirname($restLogPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $fileHandler = new \Monolog\Handler\RotatingFileHandler(
        $restLogPath,
        (int)($config['restlog_days'] ?? 14),
        \Monolog\Logger::toMonologLevel((string)($config['restlog_level'] ?? 'INFO')),
    );
    $fileHandler->setFormatter(new \Monolog\Formatter\LineFormatter());
    $logger->pushHandler($fileHandler);
}
```

If the directory cannot be created (or opening fails — Monolog throws
`UnexpectedValueException`), the exception is caught, a warning is echoed,
and the bot continues with stdout-only logging; a REST log file is not worth
killing the bot over. The handler is pushed onto the **control** logger only
— IRC bot logs stay out of the file. The same logger is passed to
`SocketHttpServer::createForDirectAccess()` and `Router`, so Amp's own
server errors land in the file too.

## Access-log middleware

New `library/RestAccessLogMiddleware.php` implementing
`Amp\Http\Server\Middleware` (method `handleRequest(Request, RequestHandler):
Response`), constructed with the control logger. After
`$response = $requestHandler->handleRequest($request)` it logs at INFO
(or WARNING for status ≥ 500):

```
"POST /aidesc" 200 5.83s from 127.0.0.1:53214
```

- method, request URI (path + query), status, handler duration (`hrtime`),
  remote address (`$request->getClient()->getRemoteAddress()`).
- **The `key` header is never logged.** ClientException from the inner
  handler propagates after logging a warning line (mirrors Amp's own
  `AccessLoggerMiddleware`, which we're not reusing only because it omits
  duration — useful for multi-second aidesc calls).

It wraps the **whole router**, not per-route, so unmatched paths (404s) and
every current/future route (notifier, `/_control/*`, `/aidesc`) are logged.
Mechanism: `Amp\Http\Server\Middleware\stackMiddleware($router,
$middleware)` produces the request handler passed to `$server->start(...)`
(Router::addMiddleware would skip unmatched requests).

## Verification

- Unit: none meaningful (Amp Request/Client are concrete classes needing a
  live socket); repo has no server-integration harness — matches how
  notifier/`/_control` are tested (manual).
- Manual on the local dev bot: start with defaults, curl `/aidesc`
  (200/garbage 400/bad key 403/unknown path 404), then inspect
  `logs/rest-YYYY-MM-DD.log` for access lines + the `aidesc [...]` detail
  line + absence of any key values; confirm stdout unchanged; confirm
  `restlog_path: ''` produces no file.
- `composer test` unaffected; `.gitignore` gains `logs/` if missing.

## Non-goals

- Art bot REST server logging (separate process/wiring)
- Byte counts in access lines
- Remote-log shipping / custom formats
