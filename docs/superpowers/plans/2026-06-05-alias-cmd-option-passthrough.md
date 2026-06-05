# Alias --cmd Option Passthrough Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow users to pass `--options` through cmd-aliases to the underlying bot command.

**Architecture:** Extract `--option[=value]` tokens from the user's alias invocation text, then append them to the expanded template value before calling `router->call()`. The target command's existing `Args::parse()` handles them. No database changes needed.

**Tech Stack:** PHP 8.1+, PHPUnit 10

---

### Task 1: Write tests for `extractOptsAndArgs` helper

**Files:**
- Create: `tests/Alias/ExtractOptsAndArgsTest.php`

- [ ] **Step 1: Write the test file**

The function is defined in `lolbot.php` which can't be included in tests (it boots the entire bot). Following the codebase pattern of including specific library files, we put `extractOptsAndArgs` in its own library file (Task 2) and include it here.

```php
<?php

namespace Tests\Alias;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../library/extract_opts_and_args.php';

class ExtractOptsAndArgsTest extends TestCase
{
    public function test_no_options(): void
    {
        [$opts, $args] = \extractOptsAndArgs('hello world');
        $this->assertSame([], $opts);
        $this->assertSame(['hello', 'world'], $args);
    }

    public function test_option_with_value(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--amt=5 cats');
        $this->assertSame(['--amt' => '5'], $opts);
        $this->assertSame(['cats'], $args);
    }

    public function test_option_without_value(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--verbose search terms');
        $this->assertSame(['--verbose' => null], $opts);
        $this->assertSame(['search', 'terms'], $args);
    }

    public function test_multiple_options(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--amt=5 --verbose cats and dogs');
        $this->assertSame(['--amt' => '5', '--verbose' => null], $opts);
        $this->assertSame(['cats', 'and', 'dogs'], $args);
    }

    public function test_empty_string(): void
    {
        [$opts, $args] = \extractOptsAndArgs('');
        $this->assertSame([], $opts);
        $this->assertSame([], $args);
    }

    public function test_only_options(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--amt=3 --verbose');
        $this->assertSame(['--amt' => '3', '--verbose' => null], $opts);
        $this->assertSame([], $args);
    }

    public function test_option_with_equals_in_value(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--fmt=a=b hello');
        $this->assertSame(['--fmt' => 'a=b'], $opts);
        $this->assertSame(['hello'], $args);
    }

    public function test_double_dash_only_not_option(): void
    {
        [$opts, $args] = \extractOptsAndArgs('-- hello');
        $this->assertSame([], $opts);
        $this->assertSame(['--', 'hello'], $args);
    }

    public function test_single_dash_not_option(): void
    {
        [$opts, $args] = \extractOptsAndArgs('-v hello');
        $this->assertSame([], $opts);
        $this->assertSame(['-v', 'hello'], $args);
    }

    public function test_mixed_options_and_args(): void
    {
        [$opts, $args] = \extractOptsAndArgs('search --amt=5 for --verbose cats');
        $this->assertSame(['--amt' => '5', '--verbose' => null], $opts);
        $this->assertSame(['search', 'for', 'cats'], $args);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test tests/Alias/ExtractOptsAndArgsTest.php`
Expected: FATAL — file `library/extract_opts_and_args.php` not found.

- [ ] **Step 3: Commit failing test**

```bash
git add tests/Alias/ExtractOptsAndArgsTest.php
git commit -m "test: add extractOptsAndArgs tests for alias option passthrough"
```

---

### Task 2: Implement `extractOptsAndArgs` in a library file

**Files:**
- Create: `library/extract_opts_and_args.php`

- [ ] **Step 1: Create the library file**

```php
<?php

/**
 * Extracts --option[=value] tokens from text, returning [opts, positionalArgs].
 * Only tokens starting with -- (two dashes) followed by at least one non-dash
 * character are treated as options. Single-dash tokens are left as positional args.
 *
 * @param string $msg
 * @return array{0: array<string, string|null>, 1: array<string>}
 */
function extractOptsAndArgs(string $msg): array {
    $opts = [];
    $args = [];
    $words = explode(' ', $msg);
    foreach ($words as $w) {
        if ($w === '') {
            continue;
        }
        if (preg_match('/^--([^-].*)$/', $w, $m)) {
            if (str_contains($m[1], '=')) {
                [$lhs, $rhs] = explode('=', $m[1], 2);
                $opts['--' . $lhs] = $rhs;
            } else {
                $opts['--' . $m[1]] = null;
            }
        } else {
            $args[] = $w;
        }
    }
    return [$opts, $args];
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `composer test tests/Alias/ExtractOptsAndArgsTest.php`
Expected: All 10 tests PASS.

- [ ] **Step 3: Commit**

```bash
git add library/extract_opts_and_args.php
git commit -m "feat: add extractOptsAndArgs helper for alias option passthrough"
```

---

### Task 2b: Include the library file in `lolbot.php`

**Files:**
- Modify: `lolbot.php` (add require_once near other library includes around line 118)

- [ ] **Step 1: Add the require_once**

Add after the existing `require_once 'library/Channels.php';` line (line 119):

```php
require_once 'library/extract_opts_and_args.php';
```

- [ ] **Step 2: Run full test suite**

Run: `composer test`
Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add lolbot.php
git commit -m "refactor: include extract_opts_and_args library in lolbot.php"
```

---

### Task 3: Wire up `extractOptsAndArgs` in the alias fallback path

**Files:**
- Modify: `lolbot.php:337-344`

- [ ] **Step 1: Update the alias invocation path**

Replace lines 337-344:

```php
                    $tmpText = $text;
                    $opts = parseOpts($tmpText, []);
                    $cmdArgs = \knivey\tools\makeArgs($tmpText);
                    if (!is_array($cmdArgs))
                        $cmdArgs = [];
                    if (count($cmdArgs) == 1 && $cmdArgs[0] == "")
                        $cmdArgs = [];
                    $alias->handleCmd($args, $bot, $cmd, $cmdArgs);
```

With:

```php
                    [$invOpts, $posArgs] = extractOptsAndArgs($text);
                    $alias->handleCmd($args, $bot, $cmd, $posArgs, $invOpts);
```

- [ ] **Step 2: Run the full test suite to verify nothing broke**

Run: `composer test`
Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add lolbot.php
git commit -m "refactor: use extractOptsAndArgs in alias invocation path"
```

---

### Task 4: Update `handleCmd` to accept and forward invocation options

**Files:**
- Modify: `scripts/alias/alias.php:197-264`

- [ ] **Step 1: Update the `handleCmd` method signature and implementation**

Change the method signature (line 197):

```php
    function handleCmd(\Irc\Event\ChatEvent $args, \Irc\Client $bot, string $cmd, array $cmdArgs): bool
```

To:

```php
    /**
     * @param \Irc\Event\ChatEvent $args
     * @param \Irc\Client $bot
     * @param string $cmd
     * @param array<string> $cmdArgs
     * @param array<string, string|null> $invOpts
     * @return bool
     */
    function handleCmd(\Irc\Event\ChatEvent $args, \Irc\Client $bot, string $cmd, array $cmdArgs, array $invOpts = []): bool
```

Then, after the `$value = str_replace(...)` call (line 243) and inside the `if (isset($alias->cmd))` block (line 245), update the `router->call` to append invocation options:

Replace lines 245-256:

```php
        if (isset($alias->cmd)) {
            if (!$this->router->cmdExists($alias->cmd)) {
                $bot->msg($args->chan, "Error with alias, bot command {$alias->cmd} not found");
                return true;
            }
            try {
                $this->router->call($alias->cmd, $value, $args, $bot);
            } catch (\Exception $e) {
                $bot->notice($args->nick, $e->getMessage());
            }
            return true;
        }
```

With:

```php
        if (isset($alias->cmd)) {
            if (!$this->router->cmdExists($alias->cmd)) {
                $bot->msg($args->chan, "Error with alias, bot command {$alias->cmd} not found");
                return true;
            }
            if (!empty($invOpts)) {
                $optStr = '';
                foreach ($invOpts as $name => $val) {
                    if ($val !== null) {
                        $optStr .= "$name=$val ";
                    } else {
                        $optStr .= "$name ";
                    }
                }
                $value = trim($value . ' ' . trim($optStr));
            }
            try {
                $this->router->call($alias->cmd, $value, $args, $bot);
            } catch (\Exception $e) {
                $bot->notice($args->nick, $e->getMessage());
            }
            return true;
        }
```

- [ ] **Step 2: Run full test suite**

Run: `composer test`
Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add scripts/alias/alias.php
git commit -m "feat: forward invocation options through cmd-aliases to target command"
```

---

### Task 5: Run static analysis and final verification

**Files:** None (verification only)

- [ ] **Step 1: Run phpstan**

Run: `composer phpstan`
Expected: No new errors.

- [ ] **Step 2: Run full test suite**

Run: `composer test`
Expected: All tests pass.

- [ ] **Step 3: Run php-cs-fixer**

Run: `vendor/bin/php-cs-fixer fix`
Expected: No changes or only minor formatting fixes.
