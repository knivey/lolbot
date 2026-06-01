# Per-Channel Rate Limit for Link Titles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the global linktitles rate limit with a per-channel sliding window rate limit.

**Architecture:** Replace the single `$link_ratelimit` integer with a per-channel array of timestamps. Before each URL fetch, prune expired entries and check against the configured limit. Log rate-limited URLs to the URL log channel if configured.

**Tech Stack:** PHP 8.1+, no new dependencies

**Spec:** `docs/superpowers/specs/2026-05-31-linktitles-per-channel-rate-limit-design.md`

---

### Task 1: Replace rate limit property and logic

**Files:**
- Modify: `scripts/linktitles/linktitles.php:56` (property)
- Modify: `scripts/linktitles/linktitles.php:75-79` (rate limit check block)

- [ ] **Step 1: Change the property from int to array**

In `scripts/linktitles/linktitles.php` line 56, change:
```php
private $link_ratelimit = 0;
```
to:
```php
private array $link_ratelimit = [];
```

- [ ] **Step 2: Replace the rate limit check block**

In `scripts/linktitles/linktitles.php` lines 75-79, replace:
```php
if (time() < $this->link_ratelimit) {
    $this->logUrl($bot, $nick, $chan, $text, "Err: Rate limit exceeded");
    return;
}
$this->link_ratelimit = time() + 2;
```
with:
```php
$maxUrls = $config['linktitles_rate_urls'] ?? 2;
$window = $config['linktitles_rate_seconds'] ?? 2;
$now = time();
$this->link_ratelimit[$chan] = array_filter(
    $this->link_ratelimit[$chan] ?? [],
    fn($ts) => $now - $ts < $window
);
if (count($this->link_ratelimit[$chan]) >= $maxUrls) {
    $this->logUrl($bot, $nick, $chan, $text, "Err: Rate limit exceeded");
    continue;
}
$this->link_ratelimit[$chan][] = $now;
```

Note: changed `return` to `continue` so remaining URLs in the message are checked individually against the rate limit rather than dropping the whole message.

- [ ] **Step 3: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: PASS (no errors)

Run: `vendor/bin/psalm`
Expected: PASS (no errors)

- [ ] **Step 4: Commit**

```bash
git add scripts/linktitles/linktitles.php
git commit -m "Replace global rate limit with per-channel sliding window"
```
