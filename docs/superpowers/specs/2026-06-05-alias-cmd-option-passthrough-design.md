# Alias --cmd Option Passthrough

## Problem

When a cmd-alias is created with `--cmd`, e.g. `!alias --cmd=bing mysearch --amt=3 $1-`, invoking it with options like `!mysearch --amt=5 cats` treats `--amt=5` as a literal positional argument. The option is never parsed by the target command's `Args` parser.

## Solution

Extract `--option[=value]` tokens from the user's invocation text and append them to the expanded alias value before calling `router->call()`. The target command's existing `Args::parse()` handles them naturally. No database changes required.

### Option precedence

Invocation options **override** template options. Invocation options are appended after the expanded template value. Since `Args::parse()` uses a case-insensitive array (`CIArray`) where the last-seen value wins, the invocation's value takes priority.

Example:
```
Alias: name=mysearch, cmd=bing, value="--amt=3 $1-"
User types: !mysearch --amt=5 cats
→ extracted opts: ['--amt' => '5']
→ remaining args: ['cats']
→ variable sub on "--amt=3 $1-": "--amt=3 cats"
→ append invocation opts: "--amt=3 cats --amt=5"
→ router->call('bing', "--amt=3 cats --amt=5", ...)
→ bing's Args parser: --amt=5 wins
```

### Changes

#### 1. `lolbot.php` — alias fallback path (lines ~335-344)

Replace the current `parseOpts`/`makeArgs` flow with a function that separates `--option[=value]` tokens from positional arguments:

```php
[$invOpts, $posArgs] = extractOptsAndArgs($text);
$alias->handleCmd($args, $bot, $cmd, $posArgs, $invOpts);
```

`extractOptsAndArgs` splits the text into words, identifies `--word` and `--word=value` tokens as options, and returns remaining words as positional args.

#### 2. `scripts/alias/alias.php` — `handleCmd` method

- Change signature to accept `$invOpts` (array of `['--name' => value|null]`)
- After variable substitution on `$value`, if the alias has `cmd` set, append invocation options to `$value` before calling `router->call()`
- Non-cmd aliases (text/action) ignore invocation options (they already have no use for them)

#### 3. No changes to

- Entity or database schema (no migration needed)
- The `knivey/cmdr` library
- Non-cmd aliases (text/action)

### Edge cases

- Options without values (e.g. `--verbose`) are passed as `--verbose` (no `=` sign), which `Args::parse()` treats as `true`
- If the invocation has no options, behavior is identical to current behavior
- If the template has no options and the invocation does, the options are simply appended to the expanded value
