# cmdr v5 Middleware Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a wrapping middleware pipeline plus declarative attribute surface to `knivey/cmdr`, the foundation step of the lolbot user system.

**Architecture:** Middlewares are callables `(Request $req, callable $next, mixed ...$args): mixed` wrapping command invocation. `Request` is widened to carry the host's `extraArgs` (ChatEvent, Client) so middlewares can resolve identities. Attributes attach aliased middleware to commands: a generic `#[CmdMiddleware]` ships with cmdr, and a `MiddlewareAttribute` interface lets hosts define pretty attributes like lolbot's future `#[Acl]`.

**Tech Stack:** PHP 8.1+, PHPUnit (both already in the Cmdr repo at `/home/knivey/PhpstormProjects/Cmdr`, branch `5.x`).

**Spec:** `docs/superpowers/specs/2026-09-26-user-system-design.md` (lolbot repo) — section "ACL surface (decided 2026-09-26)".

## Global Constraints

- Work happens in the **Cmdr repo** at `/home/knivey/PhpstormProjects/Cmdr`, branch `5.x` (already created and pushed). lolbot's `vendor/knivey/cmdr` is symlinked to it via the composer path repository — lolbot's test suite must stay green after every task (run it from `/home/knivey/PhpstormProjects/lolbot`: `vendor/bin/phpunit tests/Mal/` is a fast canary; full suite before the final task).
- Zero behavior change when no middleware is registered: `call()`/`callPriv()` keep the exact current invocation path as a fast path.
- `Request`'s constructor signature must not change (public API stability for `get()` callers).
- Existing attributes (`Cmd`, `PrivCmd`, `Syntax`, `Option`, `Options`, `Desc`, `CallWrap`) and their behavior are untouched.
- New code follows the repo's existing style (no strict_types in old files — match per-file; tests use plain PHPUnit `TestCase`).
- No tagging/releasing in tasks: v5.0.0 release timing is the owner's call. Do not tag.
- Commit style: conventional, author `knivey <knivey@botops.net>` (`git -c user.name=... -c user.email=...`), commit after each task.

## Review Focus

Five failure modes the spec implies but task tests should explicitly pin:

1. **Middleware registered for a command that is later re-registered** (host re-registers) — middlewares must not silently stack duplicates per name; last registration wins like `Cmd` re-creation does. → Task 2 test.
2. **Alias registered AFTER commands are loaded** (loadFuncs runs before aliasMiddleware) — attribute middleware must resolve lazily at call time, not load time. → Task 3 test.
3. **Middleware throwing** — exception must propagate out of `call()` unchanged (hosts catch around `call()` today). → Task 2 test.
4. **`get()` untouched** — hosts calling `get()` + `Cmd::call()` directly bypass middleware (documented behavior, not a bug): Request is what carries state; the pipeline lives in `Cmdr::call`. → Task 2 docblock + test asserting direct `Cmd::call` skips middleware.
5. **extraArgs mutation visibility** — a middleware rewriting `$req->extraArgs` before `$next()` must change what the handler receives. → Task 1 test.

---

### Task 1: Request widening + middleware storage + pipeline

**Files:**
- Modify: `/home/knivey/PhpstormProjects/Cmdr/src/Request.php`
- Modify: `/home/knivey/PhpstormProjects/Cmdr/src/Cmdr.php` (constructor, new methods, `call()`, `callPriv()`)
- Create: `/home/knivey/PhpstormProjects/Cmdr/tests/MiddlewareTest.php`

**Interfaces:**
- Consumes: existing `Request::__construct($args, $cmd)`, `Cmdr::call/callPriv(...$extraArgs)`.
- Produces (exact):
  - `Request::$extraArgs: array<int, mixed>` (public, defaults `[]`)
  - `Cmdr::addMiddleware(callable $middleware, ?string $command = null): void`
  - `Cmdr::aliasMiddleware(string $name, callable $middleware): void`
  - Middleware callable contract: `fn(Request $req, callable $next, mixed ...$args): mixed`
  - Internal: `Cmdr::runPipeline(Request $req, array $extraArgs, array $cmdMiddleware): mixed` (protected)

- [ ] **Step 1: Write failing tests** — `tests/MiddlewareTest.php`:

```php
<?php
namespace knivey\cmdr\test;

use knivey\cmdr\Cmdr;
use knivey\cmdr\Request;
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    private function cmdrWithCounter(int &$calls, array $extra = []): Cmdr
    {
        $cmdr = new Cmdr();
        $cmdr->add('hello', function (...$args) use (&$calls) {
            $calls++;
            return 'ran';
        }, syntax: '[name]');
        return $cmdr;
    }

    public function testNoMiddlewareKeepsExistingBehavior(): void
    {
        $calls = 0;
        $cmdr = $this->cmdrWithCounter($calls);
        $this->assertSame('ran', $cmdr->call('hello', 'world', 'ctx'));
        $this->assertSame(1, $calls);
    }

    public function testRequestCarriesExtraArgs(): void
    {
        $cmdr = new Cmdr();
        $seen = null;
        $cmdr->addMiddleware(function (Request $req, callable $next) use (&$seen) {
            $seen = $req->extraArgs;
            return $next($req);
        });
        $cmdr->add('hello', fn(...$a) => 'ran', syntax: '[name]');
        $cmdr->call('hello', 'x', 'ctx1', 'ctx2');
        $this->assertSame(['ctx1', 'ctx2'], $seen);
    }

    public function testGlobalMiddlewareOrderAndBubbling(): void
    {
        $order = [];
        $cmdr = new Cmdr();
        $cmdr->addMiddleware(function (Request $r, callable $n) use (&$order) { $order[] = 'a-before'; $v = $n($r); $order[] = 'a-after'; return $v . '-a'; });
        $cmdr->addMiddleware(function (Request $r, callable $n) use (&$order) { $order[] = 'b-before'; $v = $n($r); $order[] = 'b-after'; return $v . '-b'; });
        $cmdr->add('hello', fn(...$a) => 'ran', syntax: '');
        $this->assertSame('ran-b-a', $cmdr->call('hello', ''));
        $this->assertSame(['a-before', 'b-before', 'b-after', 'a-after'], $order);
    }

    public function testShortCircuitSkipsHandler(): void
    {
        $calls = 0;
        $cmdr = new Cmdr();
        $cmdr->addMiddleware(function (Request $r, callable $next) { return 'denied'; });
        $cmdr->add('hello', function (...$a) use (&$calls) { $calls++; return 'ran'; }, syntax: '');
        $this->assertSame('denied', $cmdr->call('hello', ''));
        $this->assertSame(0, $calls);
    }

    public function testPerCommandMiddlewareRunsAfterGlobal(): void
    {
        $order = [];
        $cmdr = new Cmdr();
        $cmdr->addMiddleware(function (Request $r, callable $n) use (&$order) { $order[] = 'global'; return $n($r); });
        $cmdr->addMiddleware(function (Request $r, callable $n) use (&$order) { $order[] = 'per-cmd'; return $n($r); }, 'hello');
        $cmdr->addMiddleware(function (Request $r, callable $n) use (&$order) { $order[] = 'other'; return $n($r); }, 'nope');
        $cmdr->add('hello', fn(...$a) => 'ran', syntax: '');
        $this->assertSame('ran', $cmdr->call('hello', ''));
        $this->assertSame(['global', 'per-cmd'], $order);
    }

    public function testMiddlewareExceptionPropagates(): void
    {
        $cmdr = new Cmdr();
        $cmdr->addMiddleware(function (Request $r, callable $n) { throw new \RuntimeException('boom'); });
        $cmdr->add('hello', fn(...$a) => 'ran', syntax: '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $cmdr->call('hello', '');
    }

    public function testExtraArgsMutationVisibleToHandler(): void
    {
        $cmdr = new Cmdr();
        $cmdr->addMiddleware(function (Request $r, callable $next) {
            $r->extraArgs = array_map(fn($v) => "wrapped:$v", $r->extraArgs);
            return $next($r);
        });
        $seen = null;
        $cmdr->add('hello', function (...$a) use (&$seen) { $seen = $a; return 'ran'; }, syntax: '');
        $cmdr->call('hello', '', 'ctx');
        $this->assertContains('wrapped:ctx', $seen);
    }

    public function testPrivCommandsGoThroughPipeline(): void
    {
        $cmdr = new Cmdr();
        $hit = false;
        $cmdr->addMiddleware(function (Request $r, callable $next) use (&$hit) { $hit = true; return $next($r); });
        $cmdr->add('hello', fn(...$a) => 'ran', syntax: '', priv: true);
        $this->assertSame('ran', $cmdr->callPriv('hello', ''));
        $this->assertTrue($hit);
    }

    public function testDirectCmdCallSkipsMiddleware(): void
    {
        $cmdr = new Cmdr();
        $hit = false;
        $cmdr->addMiddleware(function (Request $r, callable $next) use (&$hit) { $hit = true; return $next($r); });
        $cmdr->add('hello', fn(...$a) => 'ran', syntax: '');
        $req = $cmdr->get('hello', '');
        $this->assertSame('ran', $req->cmd->call($req->args));
        $this->assertFalse($hit);
    }
}
```

- [ ] **Step 2: Run tests, verify RED** — `cd /home/knivey/PhpstormProjects/Cmdr && vendor/bin/phpunit tests/MiddlewareTest.php` — expect failures (`Call to undefined method addMiddleware()` / undefined property `extraArgs`).

- [ ] **Step 3: Implement**

`src/Request.php` — add one property (constructor untouched):

```php
class Request
{
    public Args $args;
    public Cmd $cmd;
    /**
     * Extra arguments the command was called with (host context such as
     * ChatEvent / Client), set by Cmdr::call()/callPriv() before the
     * middleware chain runs. Middlewares may mutate it before calling $next
     * to change what the handler receives.
     * @var array<int, mixed>
     */
    public array $extraArgs = [];
```

`src/Cmdr.php` — add to the class (after the constructor):

```php
    /**
     * Global middlewares, run in registration order before per-command ones.
     * Middleware signature: fn(Request $req, callable $next, mixed ...$args): mixed
     * @var array<int, callable>
     */
    public array $globalMiddleware = [];
    /**
     * Per-command middlewares by command name.
     * @var array<string, array<int, callable>>
     */
    public array $cmdMiddleware = [];
    /**
     * Named middleware aliases resolvable from attributes.
     * @var array<string, callable>
     */
    public array $middlewareAliases = [];

    /**
     * Register a middleware. Without $command it runs globally (registration
     * order); with $command it runs only for that command (both public and
     * private instances of the name).
     */
    function addMiddleware(callable $middleware, ?string $command = null): void
    {
        if ($command === null) {
            $this->globalMiddleware[] = $middleware;
        } else {
            $this->cmdMiddleware[$command][] = $middleware;
        }
    }

    /**
     * Register a named middleware that attributes can reference by name.
     */
    function aliasMiddleware(string $name, callable $middleware): void
    {
        $this->middlewareAliases[$name] = $middleware;
    }
```

And the pipeline + rewiring of `call()`/`callPriv()` (fast path preserved):

```php
    /**
     * Runs the middleware chain around the command invocation. $entries is a
     * list of [callable $middleware, array $args] pairs in run order.
     * @param array<int, array{0: callable, 1: array}> $entries
     */
    protected function runPipeline(Request $req, array $extraArgs, array $entries): mixed
    {
        $invoke = fn(Request $r): mixed => $r->cmd->call(...[...$r->cmd->preArgs, ...$r->cmd->postArgs, ...$r->extraArgs, $r->args]);
        $req->extraArgs = $extraArgs;
        foreach (array_reverse($entries) as [$middleware, $args]) {
            $next = $invoke;
            $invoke = fn(Request $r): mixed => $middleware($r, $next, ...$args);
        }
        return $invoke($req);
    }
```

`call()` / `callPriv()` gain at the top (after the CmdNotFound check):

```php
        $entries = [];
        foreach ($this->globalMiddleware as $mw) { $entries[] = [$mw, []]; }
        foreach ($this->cmdMiddleware[$command] ?? [] as $mw) { $entries[] = [$mw, []]; }
        foreach ($req->cmd->attrMiddleware ?? [] as $mw) { $entries[] = [$this->resolveMiddleware($mw), $mw['args']]; }
        if ($entries === []) {
            return $req->cmd->call(...[...$req->cmd->preArgs, ...$req->cmd->postArgs, ...$extraArgs, $req->args]);
        }
        return $this->runPipeline($req, $extraArgs, $entries);
```

(duplicated in `callPriv()` identically; extract a private `dispatch(Request|false $req, string $command, array $extraArgs): mixed` helper that both call if you prefer DRY — the helper throws CmdNotFound on `$req === false` so docblocks keep `@throws`.)

- [ ] **Step 4: Run tests, verify GREEN** — `vendor/bin/phpunit tests/MiddlewareTest.php` (9 passing) then the full Cmdr suite `vendor/bin/phpunit` (all existing tests still green — the fast path guarantees this).

- [ ] **Step 5: lolbot canary** — from `/home/knivey/PhpstormProjects/lolbot`: `vendor/bin/phpunit tests/Mal/ tests/Alias/` green (symlinked dependency unchanged behavior).

- [ ] **Step 6: Commit** — `git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat: middleware pipeline with global, per-command and aliased middlewares"`

Note: `attrMiddleware`/`resolveMiddleware` do not exist yet — declare `public array $attrMiddleware = [];` on `Cmd` in this task (empty default, harmless) so `call()` compiles; Task 3 fills it. If the implementer prefers, guard with `method_exists` — no: just add the property, it is additive.

### Task 2: MiddlewareAttribute interface + CmdMiddleware attribute + lazy alias resolution

**Files:**
- Create: `/home/knivey/PhpstormProjects/Cmdr/src/MiddlewareAttribute.php`
- Create: `/home/knivey/PhpstormProjects/Cmdr/src/attributes/CmdMiddleware.php`
- Create: `/home/knivey/PhpstormProjects/Cmdr/src/exceptions/MiddlewareNotFound.php`
- Modify: `/home/knivey/PhpstormProjects/Cmdr/src/Cmd.php` (`attrMiddleware` property from Task 1 gets its real shape)
- Modify: `/home/knivey/PhpstormProjects/Cmdr/src/Cmdr.php` (`attrAddCmd()` collects attributes; `resolveMiddleware()` helper)
- Test: `/home/knivey/PhpstormProjects/Cmdr/tests/MiddlewareTest.php` (extend)

**Interfaces:**
- Consumes: Task 1's `attrMiddleware` property, `middlewareAliases`, pipeline entries `[callable, array]`.
- Produces:
  - `interface MiddlewareAttribute { public function name(): string; public function args(): array; }`
  - `#[Attribute(IS_REPEATABLE | TARGET_FUNCTION | TARGET_METHOD)] class CmdMiddleware implements MiddlewareAttribute` with `__construct(public string $name, mixed ...$args)` plus `name(): string`/`args(): array` satisfying the interface
  - `class MiddlewareNotFound extends \Exception`
  - `Cmd::$attrMiddleware: array<int, array{name: string, args: array}>` (public)
  - `Cmdr::resolveMiddleware(array $mw): callable` (protected, throws `MiddlewareNotFound` when the alias is unregistered — resolved lazily at call time)

- [ ] **Step 1: Write failing tests** — append to `tests/MiddlewareTest.php`:

```php
    public function testCmdMiddlewareAttributeRunsAliasedMiddlewareWithArgs(): void
    {
        $cmdr = new Cmdr();
        $gotArgs = null;
        $cmdr->aliasMiddleware('gate', function (Request $r, callable $next, ...$mwArgs) use (&$gotArgs) {
            $gotArgs = $mwArgs;
            if ($mwArgs[0] !== 'ok') return 'denied';
            return $next($r);
        });
        $cmdr->add('hello', #[\knivey\cmdr\attributes\Cmd('hello')]
            #[\knivey\cmdr\attributes\CmdMiddleware('gate', 'ok')]
            fn(...$a) => 'ran');
        $this->assertSame('ran', $cmdr->call('hello', ''));
        $this->assertSame(['ok'], $gotArgs);
    }
```

(If first-class callable attributes-on-closures do not reflect — they may not until PHP 8.4 reflection of closures with attributes — use a named function in the test file:

```php
#[\knivey\cmdr\attributes\Cmd('hello')]
#[\knivey\cmdr\attributes\CmdMiddleware('gate', 'ok')]
function mwTestHello(...$a) { return 'ran'; }
```

and register via `$cmdr->loadFuncs()` after `aliasMiddleware` is NOT needed — resolution is lazy. Verify with `ReflectionFunction` first; pick whichever registers cleanly.)

Also add: custom-interface attribute test (a test-local `#[Acl]` attribute class implementing `MiddlewareAttribute`), unknown-alias test (`expectException(MiddlewareNotFound::class)` on call), repeatable-order test (two `CmdMiddleware` attributes run in declaration order), and the re-registration test (a command name registered twice — `loadFuncs()` then `add()` — ends with a fresh `Cmd` carrying only its own attribute middleware, no stacking).

- [ ] **Step 2: Run, verify RED** (classes undefined).

- [ ] **Step 3: Implement** the three new files:

```php
<?php
namespace knivey\cmdr;

/**
 * Implement this on an Attribute class to have Cmdr attach an aliased
 * middleware to any command carrying that attribute. Hosts define pretty
 * attribute names (e.g. lolbot's #[Acl("botadmin")]) without cmdr knowing
 * anything about what the middleware does.
 */
interface MiddlewareAttribute
{
    /** Middleware alias name registered via Cmdr::aliasMiddleware() */
    public function name(): string;
    /** Arguments passed to the middleware after (Request $req, callable $next) */
    public function args(): array;
}
```

```php
<?php
namespace knivey\cmdr\attributes;

use knivey\cmdr\MiddlewareAttribute;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class CmdMiddleware implements MiddlewareAttribute
{
    /** @param string $name alias registered via Cmdr::aliasMiddleware()
     *  @param mixed ...$args forwarded to the middleware after ($req, $next) */
    public function __construct(public string $name, mixed ...$args)
    {
        $this->mwArgs = $args;
    }
    /** @var array<int, mixed> */
    public array $mwArgs = [];
    public function name(): string { return $this->name; }
    public function args(): array { return $this->mwArgs; }
}
```

```php
<?php
namespace knivey\cmdr\exceptions;

class MiddlewareNotFound extends \Exception
{
}
```

`Cmd.php`: replace Task 1's placeholder with the real shape:

```php
    /**
     * Middleware declarations collected from attributes at load time.
     * Aliases are resolved lazily at call time via Cmdr::resolveMiddleware().
     * @var array<int, array{name: string, args: array}>
     */
    public array $attrMiddleware = [];
```

`Cmdr.php` `attrAddCmd()`: after the Option collection loop, before creating the `Cmd` objects:

```php
        $attrMiddleware = [];
        foreach ($rf->getAttributes(attributes\CmdMiddleware::class) as $attr) {
            $m = $attr->newInstance();
            $attrMiddleware[] = ['name' => $m->name(), 'args' => $m->args()];
        }
        foreach ($rf->getAttributes() as $attr) {
            if (!is_subclass_of($attr->getName(), MiddlewareAttribute::class)) {
                continue;
            }
            if ($attr->getName() === attributes\CmdMiddleware::class) {
                continue; // already collected above
            }
            $m = $attr->newInstance();
            $attrMiddleware[] = ['name' => $m->name(), 'args' => $m->args()];
        }
```

then pass `$attrMiddleware` as a new trailing constructor argument to both `new Cmd(...)` sites and to `add()` (add the optional param `array $attrMiddleware = []` so manual `add()` callers stay source-compatible).

`Cmdr.php` helper used by the pipeline from Task 1:

```php
    /**
     * Resolves an attribute middleware declaration to its registered
     * callable. Aliases are resolved at call time so commands may be loaded
     * before their middleware aliases are registered.
     * @param array{name: string, args: array} $mw
     */
    protected function resolveMiddleware(array $mw): callable
    {
        if (!isset($this->middlewareAliases[$mw['name']])) {
            throw new exceptions\MiddlewareNotFound("middleware '{$mw['name']}' is not registered");
        }
        return $this->middlewareAliases[$mw['name']];
    }
```

- [ ] **Step 4: Run, verify GREEN** — full Cmdr suite + lolbot canary as in Task 1.

- [ ] **Step 5: Commit** — `feat: attribute middleware declarations with lazy alias resolution`

### Task 3: lolbot-side Acl attribute + middleware registration stub

**Files:**
- Create: `/home/knivey/PhpstormProjects/lolbot/scripts/user/Acl.php`
- Modify: `/home/knivey/PhpstormProjects/lolbot/scripts/user/Access.php` (add `before()` hook)
- Modify: `/home/knivey/PhpstormProjects/lolbot/library/BotManager.php` (register alias where the router is built, `spawn()` around line 101)
- Test: `/home/knivey/PhpstormProjects/lolbot/tests/User/AclMiddlewareTest.php`

**Interfaces:**
- Consumes: `knivey\cmdr\MiddlewareAttribute` (Task 2), `Cmdr::aliasMiddleware()`.
- Produces: `scripts\user\Acl` attribute (`#[Acl("botadmin")]`, alias `acl`), `Access::before(?callable $hook)` + hook invocation inside `allowed()`, `Access::userResolver(callable $resolver)` used by the middleware to fetch the requesting user (`($extraArgs) => ?object`).

- [ ] **Step 1: Write failing tests** (`tests/User/AclMiddlewareTest.php`) covering: `Acl` attribute maps to name `acl` + args `['flag' => 'botadmin']`; `Access::before` short-circuits `allowed()` when the hook returns true (null falls through); the registered `acl` middleware denies (returns a deny string, skips `$next`) when the resolver yields no user or `Access::allowed` is false, and calls `$next` otherwise. Build `Request` fakes with `extraArgs` arrays; no IRC needed.

- [ ] **Step 2: RED**, **Step 3: Implement** (attribute ~20 lines implementing `MiddlewareAttribute`; `before()` + `userResolver()` on `Access`; the middleware closure registered via `$router->aliasMiddleware('acl', ...)` in `BotManager::spawn()` next to `$router->loadFuncs()`; user resolution initially always `null` — the engines come in foundation step 3, so every gated command denies until the user system lands, which is correct fail-closed behavior).

- [ ] **Step 4: GREEN + full lolbot suite** (`vendor/bin/phpunit`) + scoped phpstan (`php -d memory_limit=1G vendor/bin/phpstan analyse scripts/user/ tests/User/ library/BotManager.php --no-progress`) — zero new errors vs baseline.

- [ ] **Step 5: Commit in lolbot** — `feat(user): Acl attribute and acl middleware registration (fail-closed stub)`

### Final review + follow-ups (not tasks)

- Whole-branch review across both repos' diffs (Cmdr `5.x` vs `master`; lolbot task-3 commit).
- Spec update: mark foundation step 1 complete in `docs/superpowers/specs/2026-09-26-user-system-design.md`.
- v5.0.0 tag + switching lolbot's constraint back to `^5.0`: **owner's call, not part of this plan.**
