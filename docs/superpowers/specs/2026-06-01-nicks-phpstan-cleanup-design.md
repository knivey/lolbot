# Nicks.php PHPStan Cleanup

## Problem

`library/Nicks.php` reports 38 errors at PHPStan level 5. The errors cluster
around two root causes plus a handful of one-off issues:

1. **`get_akey_nc` returns `int|string|null`** but is used exclusively on
   `$this->ppl` and `$this->tppl`, both of which are typed
   `array<string, ...>`. The widened return type cascades through ~19 errors
   (offset access, property assignment, invalid array key).
2. **Mode methods are called with `?string`** from `array_shift($modeArgs)`
   inside the `mode()` handler, while their signatures require `string`.
   10 errors.
3. **One-off issues:** `str_split` on `?string` mode string (1), `mixed`
   access on untyped `CHANMODES` option (5), `preg_match` on `?string` host
   (1), `getChanNickKey` return shape mismatch (1).

## Goal

Reduce `library/Nicks.php` to 0 PHPStan level-5 errors with no runtime
behavior change. Changes stay inside `Nicks.php` — no vendor edits, no
changes to consumer signatures.

## Constraints

- `vendor/knivey/tools` is frozen for this pass (the wider
  `get_akey_nc` signature lives there and will be revisited in its own
  project later).
- `Irc\Message` field nullability is intentional and stays as-is.
- Mode method signatures (`Owner`, `Op`, `Voice`, ...) keep `string` params;
  null is meaningless for them. Fix at the call site.
- Existing inline comments are preserved verbatim.

## Changes

### Change 1 — Add private `getKey` helper, replace `get_akey_nc`

Add a private static method to the `Nicks` class:

```php
/**
 * Case-insensitive string key lookup for $this->ppl / $this->tppl shaped arrays.
 * Returns the actual key from $haystack whose lowercase matches $needle, or null.
 */
private static function getKey(string $needle, array $haystack): ?string
{
    foreach ($haystack as $k => $_) {
        if (strcasecmp($needle, (string)$k) === 0) return (string)$k;
    }
    return null;
}
```

Replace all 17 calls to `get_akey_nc(...)` with `self::getKey(...)`. Remove
the `use function knivey\tools\get_akey_nc;` import at the top of the file.

**Parity guarantee:** `getKey` performs the same case-insensitive linear
scan as `get_akey_nc`. The `(string)$k` cast is safe because every array
passed in (`$this->ppl`, `$this->tppl`, `$this->ppl[$key]['channels']`) is
declared with `string` keys.

**Covers:** 17 call sites — lines 176, 201, 215, 219, 243, 247, 268, 293,
307, 313, 326, 352, 366, 370, 586, 621, 623.

### Change 2 — Guard `array_shift` results in `mode()` (log + throw on malformed)

The 10 mode method calls (lines 129, 131, 135, 137, 141, 143, 147, 149,
153, 155) each do `$this->Owner(array_shift($modeArgs), $args->target)`.
`array_shift` returns `?string`; methods take `string`.

Capture the shift, null-check, then call. On null (malformed MODE event —
ran out of args mid-parse), log a warning with full context and throw a
`RuntimeException`. The throw is safe: `EventEmitter::emit()` does not
catch exceptions, so it propagates to the Amp async loop's default handler
which logs and keeps the bot alive.

```php
case 'q':
    $targetNick = array_shift($modeArgs);
    if ($targetNick === null) {
        $this->bot->log->warning("Malformed MODE event: ran out of args", [
            'chan' => $args->target,
            'modeString' => $modeString ?? null,
            'modeChar' => 'q',
            'remainingArgs' => $modeArgs,
            'rawArgs' => $args->args,
        ]);
        throw new \RuntimeException(
            "Malformed MODE event on {$args->target}: ran out of args at mode char 'q'"
        );
    }
    if ($adding)
        $this->Owner($targetNick, $args->target);
    else
        $this->DeOwner($targetNick, $args->target);
    break;
```

Same pattern for `a`, `o`, `h`, `v`.

**Rationale for log + throw over silent abort:** an exhausted `$modeArgs`
mid-parse means the bot's view of channel modes is now inconsistent with
the server's. Silent corruption is the worst outcome — the operator needs
a visible signal.

**Covers:** lines 129, 131, 135, 137, 141, 143, 147, 149, 153, 155.

### Change 3 — Guard `$modeString` against null (log + throw)

Line 104: `$modeString = array_shift($modeArgs);` — `?string`.
Line 111: `foreach (str_split($modeString) as $mode)` — `str_split` rejects
`null`.

A MODE event with no mode string is malformed — same invariant violation
as running out of args mid-parse (Change 2). Handle identically: log a
warning with context, then throw.

```php
$modeString = array_shift($modeArgs);
if ($modeString === null) {
    $this->bot->log->warning("Malformed MODE event: no mode string", [
        'chan' => $args->target,
        'rawArgs' => $args->args,
    ]);
    throw new \RuntimeException(
        "Malformed MODE event on {$args->target}: no mode string in args"
    );
}
$modeArgs = array_values($modeArgs);
```

**Covers:** line 111.

### Change 4 — Guard `$CHANMODES` from `getOption` (log + throw on missing)

Line 110: `$bot->getOption('CHANMODES', [])` returns `mixed`. Lines 121–122
index into it. Without ISUPPORT CHANMODES, the bot cannot distinguish which
modes consume args — continuing to parse would silently mis-track state.
Same invariant violation as Changes 2 and 3: log a warning and throw.

```php
$rawChanmodes = $bot->getOption('CHANMODES', []);
$CHANMODES = [];
if (is_array($rawChanmodes)) {
    foreach ($rawChanmodes as $v) {
        if (is_string($v)) $CHANMODES[] = $v;
    }
}
if (count($CHANMODES) < 3) {
    $this->bot->log->warning("MODE event but server has no CHANMODES ISUPPORT", [
        'chan' => $args->target,
        'rawArgs' => $args->args,
    ]);
    throw new \RuntimeException(
        "Server is missing CHANMODES ISUPPORT; cannot safely parse MODE on {$args->target}"
    );
}
```

**Behavior change:** previously a missing CHANMODES silently fell through
to the prefix-mode switch with possibly-wrong arg consumption. Now it
fails loudly. This is the desired behavior — a server without CHANMODES
cannot have its modes tracked correctly. The `count(...) < 3` guard
covers both missing CHANMODES (empty after normalization) and malformed
CHANMODES with fewer than the expected 3 sections (A/B/C/D), since the
parser accesses offsets `[0]`, `[1]`, and `[2]`.

**Covers:** lines 121 (3 errors), 122 (2 errors).

### Change 5 — Null guard in `h2n`'s `preg_match`

Line 603: `preg_match($hostRE, $p['host'])` where `$p['host']` is
`string|null` (per the `$ppl` shape).

```php
if ($p['host'] !== null && preg_match($hostRE, $p['host'])) {
    $out[] = $n;
}
```

**Covers:** line 603.

### Change 6 — Repair `getChanNickKey` return shape (likely resolved by Change 1)

Line 363 declares `@return list<string>`. Line 374 returns `[$key, $ckey]`.

The current error stems from `get_akey_nc` returning `int|string|null` —
after the null guard, `$key` and `$ckey` are typed as
`int<min,-1>|int<1,max>|non-empty-string`, so the tuple
`[$key, $ckey]` becomes `array{0: int|string, 1: int|string}` which doesn't
fit `list<string>`.

After Change 1 swaps to `getKey` (returning `?string`), `$key` and `$ckey`
become `non-empty-string` after the null guards. The tuple
`array{0: non-empty-string, 1: non-empty-string}` should naturally satisfy
`list<string>`.

**Verification step:** after implementing Changes 1–5, run `composer phpstan`
and check whether this error persists. If gone, no action needed. If it
remains, apply `array_values` coercion:

```php
return array_values([$key, $ckey]);
```

**Covers:** line 374.

## Summary Table

| Fix | Errors | Lines touched |
|----:|-------:|--------------|
| 1. `getKey` helper | ~19 | 17 call sites + new method |
| 2. `mode()` call-site guards + log/throw | 10 | 5 case branches |
| 3. `$modeString` null guard (log + throw) | 1 | line ~104 |
| 4. `$CHANMODES` guard + log/throw on missing | 5 | lines 110–122 |
| 5. `h2n` null guard | 1 | line 603 |
| 6. `getChanNickKey` return shape (conditional) | 0–1 | line 374 |
| **Total** | **~37–39** | |

(Reported count is 38; some lines carry multiple errors.)

## Risks

- **`getKey` parity:** algorithm is identical to `get_akey_nc`. The
  `(string)$k` cast is safe because Nicks's arrays only have string keys.
- **Log + throw in event handler:** `EventEmitter::emit()` does not catch
  callback exceptions. Amp's async loop does, so the bot stays alive.
  Operator sees both the Monolog warning (with context) and Amp's
  propagated exception trace.
- **`mode()` early-return on missing `modeString`:** no state to update
  for a malformed event. Behavior matches today (no-op for unrecognized
  input).
- **Widened CHANMODES fallback:** empty array on missing/invalid ISUPPORT.
  Same observable behavior as the existing `?? ''` coalescing pattern.

## Verification

- `composer phpstan` reports 0 errors for `library/Nicks.php`.
- Diff review confirms each `getKey` substitution preserves the original
  null-check semantics at the call site.
- No changes to method signatures; no external consumers affected.

## Out of Scope

- `library/Irc/Client.php`, `library/Channels.php`, `NetworkContext.php`,
  and the small library files (`paste.php`, `Color.php`, etc.) are
  addressed in separate specs.
- Genericizing `get_akey_nc` in `vendor/knivey/tools` is deferred to that
  package's own project.
- `Irc\Message` field nullability is correct and not under review.
