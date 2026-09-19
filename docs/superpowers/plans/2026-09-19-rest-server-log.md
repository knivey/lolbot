# REST Server Log File Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the channel bot's REST server (control server) a dedicated daily-rotated log file containing a per-request access log plus the existing control-logger output.

**Architecture:** A new `library\RestAccessLogMiddleware` (Amp `Middleware`) wraps the whole router via `Middleware\stackMiddleware()` so every request — matched or 404 — logs method/target/status/duration/remote-address; it never logs the `key` header. The control logger in `lolbot.php` additionally pushes a Monolog `RotatingFileHandler` configured from new `restlog_*` config.yaml keys (on by default, `restlog_path: ''` disables). stdout logging is unchanged.

**Tech Stack:** PHP 8.1, Amp http-server 3.4 (`Middleware`, `stackMiddleware`, `ClientException`), Monolog 2.11 (`RotatingFileHandler`, `Logger::toMonologLevel()`), PHPUnit 10, league/uri 7 (test-only Uri impl).

**Spec:** `docs/superpowers/specs/2026-09-19-rest-server-log-design.md`

**Conventions:** never remove existing comments; pure additions where indicated; phpstan may need `--memory-limit=2G`; do not `git add -f`.

---

### Task 1: RestAccessLogMiddleware + unit test

**Files:**
- Create: `library/RestAccessLogMiddleware.php`
- Test: `tests/Rest/RestAccessLogMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Rest/RestAccessLogMiddlewareTest.php`:

```php
<?php

namespace Tests\Rest;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http as Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

require_once __DIR__ . '/../../vendor/autoload.php';

class RestAccessLogMiddlewareTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $logs = [];

    private function makeMiddleware(): \library\RestAccessLogMiddleware
    {
        $this->logs = [];
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('log')->willReturnCallback(function (string $level, string $message): void {
            $this->logs[] = [$level, $message];
        });
        return new \library\RestAccessLogMiddleware($logger);
    }

    private function makeRequest(): Request
    {
        $client = $this->createStub(\Amp\Http\Server\Client::class);
        $client->method('getRemoteAddress')->willReturn(new \Amp\Socket\InternetAddress('127.0.0.1', 53214));
        return new Request($client, 'POST', Uri::new('/aidesc?x=1'));
    }

    public function test_logs_request_line_at_info(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willReturn(new Response(200));
        $response = $this->makeMiddleware()->handleRequest($this->makeRequest(), $handler);

        $this->assertSame(200, $response->getStatus());
        $this->assertCount(1, $this->logs);
        [$level, $message] = $this->logs[0];
        $this->assertSame(LogLevel::INFO, $level);
        $this->assertMatchesRegularExpression(
            '#^"POST /aidesc\?x=1" 200 (?:\d+(?:\.\d+)?s|\d+(?:\.\d+)?ms|\d+µs) from 127\.0\.0\.1:53214$#',
            $message,
        );
    }

    public function test_5xx_logs_at_warning(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willReturn(new Response(500));
        $this->makeMiddleware()->handleRequest($this->makeRequest(), $handler);

        $this->assertCount(1, $this->logs);
        $this->assertSame(LogLevel::WARNING, $this->logs[0][0]);
    }

    public function test_client_exception_is_logged_and_rethrown(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willThrowException(
            new ClientException($this->createStub(\Amp\Http\Server\Driver\Client::class), 'peer vanished')
        );

        try {
            $this->makeMiddleware()->handleRequest($this->makeRequest(), $handler);
            $this->fail('ClientException should have propagated');
        } catch (ClientException) {
        }

        $this->assertCount(1, $this->logs);
        $this->assertSame(LogLevel::WARNING, $this->logs[0][0]);
        $this->assertStringContainsString('"POST /aidesc?x=1"', $this->logs[0][1]);
        $this->assertStringContainsString('peer vanished', $this->logs[0][1]);
    }

    public function test_key_header_value_never_logged(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willReturn(new Response(403));
        $request = $this->makeRequest();
        $request->setHeader('key', 's3cr3tkey');

        $this->makeMiddleware()->handleRequest($request, $handler);

        $this->assertCount(1, $this->logs);
        $this->assertStringNotContainsString('s3cr3tkey', $this->logs[0][1]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Rest/RestAccessLogMiddlewareTest.php`
Expected: FAIL — `Class "library\RestAccessLogMiddleware" not found`

- [ ] **Step 3: Implement the middleware**

Create `library/RestAccessLogMiddleware.php` (namespace `library` is PSR-4 mapped via composer; see `library/BotManager.php` for the precedent):

```php
<?php

namespace library;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Access log for the channel bot's REST server: one line per request
 * (method, target, status, handler duration, remote address) once the inner
 * handler returns. Wraps the whole router rather than being registered
 * per-route, so unmatched paths (404s) are logged too. The key header is
 * deliberately never logged.
 */
final class RestAccessLogMiddleware implements Middleware
{
    public function __construct(private LoggerInterface $logger) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $start = hrtime(true);
        $method = $request->getMethod();
        $uri = (string)$request->getUri();
        $remote = $request->getClient()->getRemoteAddress()->toString();

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (ClientException $e) {
            // Client vanished mid-request; mirrors Amp's AccessLoggerMiddleware.
            $this->logger->warning(\sprintf(
                'Client exception for "%s %s" from %s: %s',
                $method,
                $uri,
                $remote,
                $e->getMessage(),
            ));
            throw $e;
        }

        $ms = (hrtime(true) - $start) / 1e6;
        $status = $response->getStatus();
        $level = $status >= 500 ? LogLevel::WARNING : LogLevel::INFO;
        $this->logger->log($level, \sprintf(
            '"%s %s" %d %s from %s',
            $method,
            $uri,
            $status,
            self::formatDuration($ms),
            $remote,
        ));
        return $response;
    }

    private static function formatDuration(float $ms): string
    {
        if ($ms < 1) {
            return round($ms * 1000) . 'µs';
        }
        if ($ms < 1000) {
            return round($ms, 1) . 'ms';
        }
        return round($ms / 1000, 2) . 's';
    }
}
```

(`formatDuration` mirrors `scripts\linktitles\linktitles::formatDuration`'s tiering — µs/ms/s — which is private and bot-scoped, so a local copy keeps this class standalone.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Rest/RestAccessLogMiddlewareTest.php`
Expected: PASS (4 tests). If the duration regex is too strict for the actual formatted output, adjust the REGEX IN THE TEST to match real output (never the assertion intent).

- [ ] **Step 5: Commit**

```bash
git add library/RestAccessLogMiddleware.php tests/Rest/RestAccessLogMiddlewareTest.php
git commit -m "feat(rest): request access log middleware"
```

---

### Task 2: Wire file handler + middleware into lolbot.php; config docs; gitignore

**Files:**
- Modify: `lolbot.php` (logger wiring after line 159; router wrapping at line 245; one new import)
- Modify: `config.example.yaml` (document the three keys near the `listen`/`control_key` block)
- Modify: `.gitignore` (add `logs/`)

- [ ] **Step 1: Add the file handler to the control logger**

In `lolbot.php`, the control-server block currently reads (lines 155-162):

```php
    // Global REST server (single listen + control_key), if configured.
    $server = null;
    if (isset($config['listen'])) {
        $logger = new Logger("control");
        $logger->pushHandler($logHandler);
```

Replace the two logger lines with (keep the comment lines above untouched):

```php
        $logger = new Logger("control");
        $logger->pushHandler($logHandler);
        // REST log file: daily rotation, control-server output only.
        $restLogPath = trim((string)($config['restlog_path'] ?? 'logs/rest.log'));
        if ($restLogPath !== '') {
            $restLogDir = dirname($restLogPath);
            if (!is_dir($restLogDir)) {
                @mkdir($restLogDir, 0775, true);
            }
            try {
                $restFileHandler = new \Monolog\Handler\RotatingFileHandler(
                    $restLogPath,
                    (int)($config['restlog_days'] ?? 14),
                    \Monolog\Logger::toMonologLevel((string)($config['restlog_level'] ?? 'INFO')),
                );
                $restFileHandler->setFormatter(new \Monolog\Formatter\LineFormatter());
                $logger->pushHandler($restFileHandler);
            } catch (\Throwable $e) {
                // A log file is not worth killing the bot over; continue stdout-only.
                echo "REST log disabled ({$restLogPath}): " . $e->getMessage() . "\n";
            }
        }
```

Also add to the `use` block at the top of `lolbot.php` (after `use Monolog\Logger;`):

```php
use library\RestAccessLogMiddleware;
use function Amp\Http\Server\Middleware\stackMiddleware;
```

- [ ] **Step 2: Wrap the router with the middleware**

In `lolbot.php`, replace the server start call (line 245):

```php
        $server->start($router, new \Amp\Http\Server\DefaultErrorHandler());
```

with (keeping the surrounding lines untouched):

```php
        // Access log wraps the whole router so 404s are logged too
        // (Router::addMiddleware would only cover matched routes).
        $server->start(
            stackMiddleware($router, new RestAccessLogMiddleware($logger)),
            new \Amp\Http\Server\DefaultErrorHandler()
        );
```

- [ ] **Step 3: Document config keys + ignore the logs dir**

In `config.example.yaml`, find the control-server block (the `listen:` / `control_key:` lines near the top) and add after `control_key`:

```yaml
# REST server log file (daily rotation). restlog_path is the base name; the
# handler writes <path without extension>-YYYY-MM-DD.log. Empty disables.
#restlog_path: "logs/rest.log"
#restlog_days: 14
#restlog_level: "INFO"
```

In `.gitignore`, add a line after `*.db`:

```
logs/
```

- [ ] **Step 4: Syntax + static analysis + suite**

Run:

```bash
php -l lolbot.php && php -l library/RestAccessLogMiddleware.php
vendor/bin/phpstan analyse library/RestAccessLogMiddleware.php lolbot.php tests/Rest/RestAccessLogMiddlewareTest.php --no-progress --memory-limit=2G
composer test
```

Expected: no syntax errors; phpstan — zero errors in the middleware/test, `lolbot.php` no NEW errors vs before the change (compare counts before/after); full suite passes.

- [ ] **Step 5: Commit**

```bash
git add lolbot.php config.example.yaml .gitignore
git commit -m "feat(rest): control server log file with daily rotation + access log"
```

---

### Task 3: Live verification on the local dev bot

**Files:** none (verification only). The local bot connects to the birdnest dev IRC server — safe to run.

- [ ] **Step 1: Create a test key (needed for a real /aidesc hit)**

Run: `php admin-cli.php apikey:add restlogtest --label restlog --scope aidesc` and note the id.

- [ ] **Step 2: Start the bot and exercise the matrix**

```bash
nohup php lolbot.php > /tmp/opencode/bot.log 2>&1 & echo $! > /tmp/opencode/bot.pid
sleep 5
curl -s --max-time 30 -w ' [%{http_code}]\n' -X POST -H "key: restlogtest" --data-binary @tests/fixtures/100x50_red.jpg http://127.0.0.1:1779/aidesc
curl -s --max-time 10 -w ' [%{http_code}]\n' -X POST -H "key: nope" --data-binary 'x' http://127.0.0.1:1779/aidesc
curl -s --max-time 10 -w ' [%{http_code}]\n' http://127.0.0.1:1779/no/such/path
```

Expected: 200 + description, 403, 404.

- [ ] **Step 3: Inspect the log file**

Run: `ls logs/ && cat logs/rest-$(date +%Y-%m-%d).log`
Expected: lines for all three requests (`"POST /aidesc" 200 ...s from ...`, 403, 404), the `aidesc [restlog] ok ...` detail line from the control logger, Amp server startup lines, and **no occurrence of any key value** (`grep -c restlogtest logs/rest-*.log` — expect matches ONLY in the `aidesc [restlog]` label position if the label equals the key name; use a label distinct from the key to make this check unambiguous — that's why the key is `restlogtest` but the label is `restlog`).

- [ ] **Step 4: Verify disable path + cleanup**

Add `restlog_path: ""` to the local `config.yaml`, restart the bot, curl once, confirm no new `logs/rest-*` file appears and stdout still shows control lines; then remove the `restlog_path: ""` line from `config.yaml`. Delete the test key: `php admin-cli.php apikey:del <id>`. Stop the bot: `kill $(cat /tmp/opencode/bot.pid)`; `rm -f /tmp/opencode/bot.pid /tmp/opencode/bot.log`. Confirm `git status --porcelain` is clean (logs/ is ignored).

- [ ] **Step 5: Report**

Report: matrix results, log file contents (redact nothing — keys must not be in there), phpstan delta, composer test counts, and the final commit list (`git log --oneline 2ffc980..HEAD`).
