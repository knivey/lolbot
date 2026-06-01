# Per-Channel Rate Limit for Link Titles

## Problem

The current linktitles rate limiting uses a single global 2-second cooldown
(`$link_ratelimit`). This has two issues:

1. Not per-channel — a URL in one channel delays titles in all other channels
2. The cooldown is consumed by HTTP fetch time, so multiple URLs in a single
   message can all get titled (the fetch itself satisfies the 2-second wait)

## Requirements

- Rate limit is per IRC channel (independent per channel)
- Default: 2 URLs per 2-second sliding window
- URLs exceeding the limit are not titled but are logged to the URL log channel (if configured) with "Err: Rate limit exceeded"
- Configurable via `config.yaml` with flat keys
- Duplicate URL filter (`$link_history`) remains unchanged

## Config

Two new optional keys in `config.yaml`:

```yaml
linktitles_rate_urls: 2     # max URLs to title per window (default: 2)
linktitles_rate_seconds: 2  # window in seconds (default: 2)
```

Missing keys fall back to the defaults. No config migration needed.

## Implementation

### File: `scripts/linktitles/linktitles.php`

**Replace the rate limit property:**

```
- private $link_ratelimit = 0;
+ private array $link_ratelimit = [];
```

Each key is a channel name, each value is an array of `time()` timestamps
representing when a URL was titled in that channel.

**Replace the rate limit check (current lines 75-79):**

Remove:
```php
if (time() < $this->link_ratelimit) {
    $this->logUrl($bot, $nick, $chan, $text, "Err: Rate limit exceeded");
    return;
}
$this->link_ratelimit = time() + 2;
```

Insert sliding window logic:
1. Read config: `$maxUrls = $config['linktitles_rate_urls'] ?? 2` and `$window = $config['linktitles_rate_seconds'] ?? 2`
2. Prune: filter `$this->link_ratelimit[$chan]` to keep only entries where `time() - entry < $window`
3. Check: if `count($this->link_ratelimit[$chan]) >= $maxUrls`, log via `$this->logUrl($bot, $nick, $chan, $text, "Err: Rate limit exceeded")` then `continue`
4. Record: append `time()` to `$this->link_ratelimit[$chan]` before fetching

The prune-and-check happens inside the existing `foreach` loop over words,
so it applies per-URL within a message (e.g., 3 URLs in one message: first 2
titled, 3rd silently skipped).

### No other files change

The calling code in `lolbot.php` line 280 is unaffected. Config defaults mean
no changes to `config.yaml` or `config.example.yaml` are required (optional
addition for documentation purposes only).

## Edge Cases

- **Channel not yet seen:** `$this->link_ratelimit[$chan]` defaults to empty
  array via null coalesce — first URL always passes
- **Window expiry:** old timestamps are pruned on every check, so memory does
  not grow unbounded
- **Bot restart:** rate limit state is in-memory only, resets on restart
  (acceptable — same as current behavior)
