# Linktitles reasoning (JSON) web form Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `reasoning (JSON)` an editable form field on all three linktitles panel tiers (global / network / channel) following the existing inherit-field semantics, replacing the read-only "editable via CLI" text.

**Architecture:** The panel's linktitles pages already have a field-descriptor pipeline (`web_lt_desc()` → `inherit_field` Twig macro → `web_lt_apply()` save loop) that implements prefill-when-overridden / blank-when-inherited / blank-save-inherits. We add `ai_vision_reasoning` as a new `json` field type flowing through that pipeline, validate JSON up front in the save flow (aborting the whole save on bad input), and delete the static read-only template blocks. Backend (`ConfigService`, `SettingsResolver`) already fully supports the key — no changes there.

**Tech Stack:** PHP 8.1 (flat-function web layer), Twig 3, PHPUnit (`tests/Config/` function-surface conventions), PHPStan level 9.

**Spec:** `docs/superpowers/specs/2026-09-02-linktitles-reasoning-json-web-form-design.md`

---

### Task 1: `web_lt_parse_reasoning_json()` helper (TDD)

**Files:**
- Create: `tests/Config/WebLinktitlesReasoningTest.php`
- Modify: `web/sections/linktitles.php` (add helper after `web_lt_desc()`, ~line 26)

- [ ] **Step 1: Write the failing test**

Create `tests/Config/WebLinktitlesReasoningTest.php` with exactly this content:

```php
<?php
namespace Tests\Config;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Pure helpers for the reasoning (JSON) form field on the linktitles panel.
 * Function-surface tests: the section file is require_once'd directly
 * (WebAuthTest pattern); no routing/session involved.
 */
class WebLinktitlesReasoningTest extends ConfigTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../web/sections/linktitles.php';
    }

    public function test_parses_json_object(): void
    {
        $out = web_lt_parse_reasoning_json('{"effort":"low","enabled":true}');
        $this->assertSame(['effort' => 'low', 'enabled' => true], $out);
    }

    public function test_empty_object_is_valid(): void
    {
        $this->assertSame([], web_lt_parse_reasoning_json('{}'));
        $this->assertSame([], web_lt_parse_reasoning_json('[]'));
    }

    public function test_rejects_non_empty_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string keys');
        web_lt_parse_reasoning_json('[1,2]');
    }

    public function test_rejects_scalar_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON object');
        web_lt_parse_reasoning_json('5');
    }

    public function test_rejects_invalid_syntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON object');
        web_lt_parse_reasoning_json('{effort:low}');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/WebLinktitlesReasoningTest.php`
Expected: FAIL / ERROR — `Call to undefined function web_lt_parse_reasoning_json()`

- [ ] **Step 3: Write minimal implementation**

In `web/sections/linktitles.php`, insert immediately after the `web_lt_desc()` function (which ends around line 26):

```php
/**
 * Parse a reasoning (JSON) form value. Must decode to an object (a
 * string-keyed array, possibly empty); non-empty lists, scalars and invalid
 * syntax are rejected so ConfigService::setLinktitlesSetting can never throw
 * mid-apply.
 *
 * @return array<string, mixed>
 */
function web_lt_parse_reasoning_json(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new \InvalidArgumentException('reasoning must be a JSON object');
    }
    foreach ($decoded as $k => $_) {
        if (!is_string($k)) {
            throw new \InvalidArgumentException('reasoning must be a JSON object with string keys');
        }
    }
    return $decoded;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/WebLinktitlesReasoningTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add tests/Config/WebLinktitlesReasoningTest.php web/sections/linktitles.php
git commit -m "feat(web): reasoning JSON parse helper for linktitles panel"
```

---

### Task 2: Reasoning field descriptors (TDD)

**Files:**
- Modify: `tests/Config/WebLinktitlesReasoningTest.php` (add descriptor tests)
- Modify: `web/sections/linktitles.php` (`web_lt_global_fields()` and `web_lt_resolved_fields()`)

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Config/WebLinktitlesReasoningTest.php` (inside the class, after the existing test methods):

```php
    /**
     * @param list<array{name:string,label:string,type:string,value:string,source:string,hint:string}> $fields
     * @return array{name:string,label:string,type:string,value:string,source:string,hint:string}|null
     */
    private function findField(array $fields, string $name): ?array
    {
        foreach ($fields as $f) {
            if ($f['name'] === $name) {
                return $f;
            }
        }
        return null;
    }

    public function test_global_fields_reasoning_when_set(): void
    {
        $s = new \scripts\linktitles\entities\linktitles_setting();
        $s->ai_vision_reasoning = ['effort' => 'low'];
        $f = $this->findField(web_lt_global_fields($s), 'ai_vision_reasoning');
        $this->assertNotNull($f);
        $this->assertSame('json', $f['type']);
        $this->assertSame('global', $f['source']);
        $this->assertSame('{"effort":"low"}', $f['value']);
        $this->assertSame('default: (none)', $f['hint']);
    }

    public function test_global_fields_reasoning_default_when_null(): void
    {
        $f = $this->findField(web_lt_global_fields(null), 'ai_vision_reasoning');
        $this->assertNotNull($f);
        $this->assertSame('default', $f['source']);
        $this->assertSame('', $f['value']);
    }

    /**
     * @param array<string, string> $sourcesOverride
     */
    private function resolvedWith(string $reasoningSource, ?array $reasoning): \lolbot\config\LinktitlesResolved
    {
        return new \lolbot\config\LinktitlesResolved(
            enabled: true,
            urlLogChan: null,
            aiVisionModel: 'model',
            aiVisionPrompt: 'prompt',
            aiVisionReasoningEffort: null,
            aiVisionReasoning: $reasoning,
            aiVisionDisabled: false,
            sources: [
                'enabled' => 'global',
                'ai_vision_disabled' => 'global',
                'url_log_chan' => 'default',
                'ai_vision_model' => 'global',
                'ai_vision_prompt' => 'global',
                'ai_vision_reasoning_effort' => 'default',
                'ai_vision_reasoning' => $reasoningSource,
            ],
        );
    }

    public function test_resolved_fields_reasoning_inherited_hint(): void
    {
        $f = $this->findField(
            web_lt_resolved_fields($this->resolvedWith('network', ['effort' => 'low'])),
            'ai_vision_reasoning',
        );
        $this->assertNotNull($f);
        $this->assertSame('json', $f['type']);
        $this->assertSame('network', $f['source']);
        $this->assertSame('{"effort":"low"}', $f['value']);
        $this->assertSame('inherits: {"effort":"low"} (from network)', $f['hint']);
    }

    public function test_resolved_fields_reasoning_none(): void
    {
        $f = $this->findField(
            web_lt_resolved_fields($this->resolvedWith('default', null)),
            'ai_vision_reasoning',
        );
        $this->assertNotNull($f);
        $this->assertSame('default', $f['source']);
        $this->assertSame('', $f['value']);
        $this->assertSame('inherits: (none) (from default)', $f['hint']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/WebLinktitlesReasoningTest.php`
Expected: FAIL — the four descriptor tests fail (`null` returned by `findField`); helper tests still pass.

- [ ] **Step 3: Implement the descriptors**

In `web/sections/linktitles.php`:

**(a)** In `web_lt_resolved_fields()`, replace the `$shown` and `$hint` closures (they currently don't handle arrays) with:

```php
    $shown = static function (bool|string|array|null $v): string {
        if (is_bool($v)) {
            return $v ? 'on' : 'off';
        }
        if ($v === null) {
            return '(none)';
        }
        if (is_array($v)) {
            $enc = json_encode($v, JSON_UNESCAPED_SLASHES);
            return $enc === false ? '(none)' : $enc;
        }
        return (string)$v;
    };
    $hint = static fn(bool|string|array|null $v, string $src): string => 'inherits: ' . $shown($v) . ' (from ' . $src . ')';
```

**(b)** In `web_lt_resolved_fields()`'s returned array, insert after the `ai_vision_reasoning_effort` entry:

```php
        web_lt_desc('ai_vision_reasoning', 'reasoning (JSON)', 'json',
            $r->aiVisionReasoning !== null ? ((string)(json_encode($r->aiVisionReasoning, JSON_UNESCAPED_SLASHES) ?: '')) : '',
            $sources['ai_vision_reasoning'],
            $hint($r->aiVisionReasoning, $sources['ai_vision_reasoning'])),
```

**(c)** In `web_lt_global_fields()`, insert after the `ai_vision_reasoning_effort` block (before `return $fields;`):

```php
    if ($g !== null && $g->ai_vision_reasoning !== null) {
        $encoded = json_encode($g->ai_vision_reasoning, JSON_UNESCAPED_SLASHES);
        $val = $encoded === false ? '' : $encoded;
        $src = 'global';
    } else {
        $val = '';
        $src = 'default';
    }
    $fields[] = web_lt_desc('ai_vision_reasoning', 'reasoning (JSON)', 'json', $val, $src, 'default: (none)');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/WebLinktitlesReasoningTest.php`
Expected: PASS (9 tests)

- [ ] **Step 5: Commit**

```bash
git add tests/Config/WebLinktitlesReasoningTest.php web/sections/linktitles.php
git commit -m "feat(web): reasoning JSON descriptors for linktitles tier forms"
```

---

### Task 3: Save flow in `web_lt_apply()` (pre-parse, set/reset, error path)

**Files:**
- Modify: `web/sections/linktitles.php` — `web_lt_apply()` (~line 195)

No new unit tests: `web_lt_apply()` ends in `exit` via the render helpers (same as today — it is untested in-repo); correctness of the parse step is covered by Task 1. Full-suite regression runs in Task 4/5.

- [ ] **Step 1: Replace `web_lt_apply()` with the version below**

Keep the existing docblock, extending it to mention the reasoning pre-parse:

```php
/**
 * Apply posted linktitles settings for a scope. Empty text / "inherit" radio
 * resets the field to inherit; otherwise the value is stored. reasoning
 * (JSON) is parsed and validated up front so a bad value aborts the whole
 * save instead of leaving the other fields half-applied.
 */
function web_lt_apply(?Network $net, ?Channel $chan): void
{
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        if ($chan !== null) {
            web_linktitles_channel($chan->id, $e->getMessage());
        }
        web_linktitles($e->getMessage());
    }

    $svc = web_app()['svc'];

    $reasoningRaw = trim(is_string($_POST['ai_vision_reasoning'] ?? null) ? $_POST['ai_vision_reasoning'] : '');
    $reasoning = null;
    if ($reasoningRaw !== '') {
        try {
            $reasoning = web_lt_parse_reasoning_json($reasoningRaw);
        } catch (\InvalidArgumentException $e) {
            if ($chan !== null) {
                web_linktitles_channel($chan->id, $e->getMessage());
            }
            web_linktitles($e->getMessage());
        }
    }

    foreach (['url_log_chan', 'ai_vision_model', 'ai_vision_prompt', 'ai_vision_reasoning_effort'] as $k) {
        $raw = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
        if ($raw === '') {
            $svc->resetLinktitlesSetting($net, $chan, $k);
            continue;
        }
        $svc->setLinktitlesSetting($net, $chan, $k, $raw);
    }
    foreach (['enabled', 'ai_vision_disabled'] as $k) {
        $v = is_string($_POST[$k] ?? null) ? $_POST[$k] : 'inherit';
        if ($v === 'inherit') {
            $svc->resetLinktitlesSetting($net, $chan, $k);
            continue;
        }
        $svc->setLinktitlesSetting($net, $chan, $k, $v === 'on');
    }

    if ($reasoningRaw === '') {
        $svc->resetLinktitlesSetting($net, $chan, 'ai_vision_reasoning');
    } else {
        $svc->setLinktitlesSetting($net, $chan, 'ai_vision_reasoning', $reasoning);
    }
}
```

- [ ] **Step 2: Run the Config test group as a regression check**

Run: `vendor/bin/phpunit tests/Config`
Expected: PASS (all pre-existing tests still green)

- [ ] **Step 3: Commit**

```bash
git add web/sections/linktitles.php
git commit -m "feat(web): save reasoning JSON from linktitles tier forms"
```

---

### Task 4: Macro `json` type + template cleanup

**Files:**
- Modify: `web/templates/_macros.twig` (`inherit_field` macro, ~line 28-49)
- Modify: `web/templates/linktitles.twig` (remove static reasoning blocks)
- Modify: `web/templates/linktitles/channel.twig` (remove static reasoning block)
- Modify: `web/sections/linktitles.php` (remove now-unused `globalReasoningJson` / `reasoningJson` template vars)

- [ ] **Step 1: Add the `json` branch to the `inherit_field` macro**

In `web/templates/_macros.twig`, insert after the `{% elseif type == 'textarea' %}` branch and before the final `{% else %}` input branch:

```twig
    {% elseif type == 'json' %}
      <textarea class="form-control font-monospace" name="{{ name }}" rows="4">{{ overridden ? value : '' }}</textarea>
```

- [ ] **Step 2: Remove the static reasoning block from `web/templates/linktitles.twig`**

Delete this block (global card, after the `{% endfor %}` of the fields loop):

```twig
    <div class="mb-3">
      <label class="form-label">reasoning (JSON)</label>
      <div class="form-text text-body-secondary">editable via CLI &mdash; {% if globalReasoningJson %}<code>{{ globalReasoningJson }}</code>{% else %}(not set){% endif %}</div>
    </div>
```

And delete this block (network card, after its `{% endfor %}`):

```twig
    <div class="mb-3">
      <label class="form-label">reasoning (JSON)</label>
      <div class="form-text text-body-secondary">editable via CLI &mdash; {% if n.reasoningJson %}<code>{{ n.reasoningJson }}</code>{% else %}(not set){% endif %}</div>
    </div>
```

- [ ] **Step 3: Remove the static reasoning block from `web/templates/linktitles/channel.twig`**

Delete:

```twig
    <div class="mb-3">
      <label class="form-label">reasoning (JSON)</label>
      <div class="form-text text-body-secondary">editable via CLI &mdash; {% if reasoningJson %}<code>{{ reasoningJson }}</code>{% else %}(not set){% endif %}</div>
    </div>
```

- [ ] **Step 4: Remove the now-unused template variables in `web/sections/linktitles.php`**

In `web_linktitles()`, delete from the `$networks[]` entry:

```php
            'reasoningJson' => $resolved->aiVisionReasoning !== null ? json_encode($resolved->aiVisionReasoning, JSON_PRETTY_PRINT) : '',
```

and delete from the `web_render('linktitles.twig', ...)` argument list:

```php
        'globalReasoningJson' => ($globalRow !== null && $globalRow->ai_vision_reasoning !== null) ? json_encode($globalRow->ai_vision_reasoning, JSON_PRETTY_PRINT) : '',
```

In `web_linktitles_channel()`, delete from the `web_render('linktitles/channel.twig', ...)` argument list:

```php
        'reasoningJson' => $resolved->aiVisionReasoning !== null ? json_encode($resolved->aiVisionReasoning, JSON_PRETTY_PRINT) : '',
```

- [ ] **Step 5: Regression run**

Run: `composer test`
Expected: PASS (full suite)

- [ ] **Step 6: Commit**

```bash
git add web/templates/_macros.twig web/templates/linktitles.twig web/templates/linktitles/channel.twig web/sections/linktitles.php
git commit -m "feat(web): editable reasoning JSON field on linktitles tier forms"
```

---

### Task 5: Verification

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: PASS, no failures

- [ ] **Step 2: PHPStan scoped to touched paths**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse web/ tests/Config/WebLinktitlesReasoningTest.php --no-progress`
Expected: no NEW errors attributable to these changes (the repo carries a pre-existing baseline elsewhere; errors, if any, must be checked against untouched lines only).

- [ ] **Step 3: Manual panel round-trip (user-driven)**

From the repo root: `php -S 127.0.0.1:8080 web/index.php`, log in at `http://127.0.0.1:8080/login` with the configured `control_key`, then:

1. `/linktitles` — global card shows a `reasoning (JSON)` monospace textarea.
2. Type `{"effort":"low","enabled":true}` in the global card, Save global — page reloads with the JSON prefilled (compact, no pretty-print).
3. On a network card: textarea blank with hint `inherits: {"effort":"low"} (from global)`; type garbage (`{nope`), Save — error alert shown, no settings from that form applied.
4. Save the same form with the textarea blank — reasoning resets to inherit.
5. Channel page — same behavior at channel tier.
6. Confirm `php admin-cli.php linktitles:set --global` (no args) reflects the changes; running bot picks them up live (no restart).

---

## Self-review notes

- Spec coverage: compact JSON (JSON_UNESCAPED_SLASHES, no pretty-print) ✓ (Tasks 2, 4); server-side validation with whole-save abort ✓ (Tasks 1, 3); inherit semantics identical to other fields ✓ (Tasks 2, 4); static "editable via CLI" blocks + template vars removed ✓ (Task 4); ConfigService/SettingsResolver/CLI untouched ✓; tests per repo conventions ✓ (Tasks 1-2); suite + scoped phpstan ✓ (Task 5).
- Type consistency: `web_lt_parse_reasoning_json(): array` (Task 1) matches its use in `web_lt_apply()` (Task 3); field type string `'json'` consistent across descriptors (Task 2) and macro branch (Task 4); `findField()` defined once in Task 2 and used only there.
- `web_lt_apply()`'s docblock is preserved and extended, per repo rule that existing comments must be carried over.
