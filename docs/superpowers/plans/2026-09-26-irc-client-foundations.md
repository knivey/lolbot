# Irc\Client Foundations (User System Step 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `Irc\Client` the protocol foundations the user-system identity engines need: IRCv3 message tags, `account-tag` + `extended-join` CAPs surfaced on typed events, and a WHOX API with label-correlated async results.

**Architecture:** Protocol-pure additions only — no identity logic in the client (rebinding correlation belongs to the engines layer per owner decision 2026-09-26). Tags parse in `Message`; the account rides one optional `UserEvent::$account` constructor param inherited by every user-origin event; WHOX correlates 354 replies by label and resolves an `Amp\Future` on 315.

**Tech Stack:** PHP 8.1+, Amp/Revolt (Future/Suspension/EventLoop), PHPUnit; repo at /home/knivey/PhpstormProjects/lolbot, master.

**Spec:** `docs/superpowers/specs/2026-09-26-user-system-design.md` — "Auth engines", "Identity cache", "Foundations build order" (step 2).

## Global Constraints

- Client stays protocol-pure: NO identity/rebinding state (owner decision).
- `UserEvent::__construct` gains ONLY an optional trailing param `?string $account = null` — every existing construction site (library + tests + lolbot scripts if any) stays source-compatible; default null everywhere unless a tag provides it.
- `account-tag` value `*` (logged out) normalizes to `null` at the event-population sites.
- CAP REQs are conditional on the server advertising the cap in CAP LS (same pattern as `multi-prefix`); servers without them (GameSurge) must see no behavior change.
- `Message::parse` with no `@tags` prefix produces `null` tags — all existing parse behavior byte-identical (existing regex untouched for untagged lines).
- Existing attributes/Cmdr untouched. AGENTS.md: never remove comments; phpstan level 9 zero NEW errors on touched files; conventional commits authored `knivey <knivey@botops.net>`; never `git add -f`.
- The IRC library is a modified in-repo fork (`library/Irc`), NOT the vendored package — edits happen here.

## Review Focus

1. **Tagged-line regression** — an untagged line must parse byte-identically to today (the whole bot rides this parser). → Task 1 exhaustive side-by-side tests.
2. **Malformed tags** — `@` alone, empty value (`k=`), valueless key (`@away`), escapes at value end (`\` trailing), `;` inside escaped value. → Task 1 tests.
3. **CAP LS without the new caps** — no REQ sent, no CAP END desync (the sasl/multi-prefix dance must not change). → Task 3 test with CAP LS lacking new caps.
4. **WHOX label collision** — two concurrent whox() calls; interleaved 354s land in the right futures; a 354 with an unknown label is ignored, not fatal. → Task 4 tests.
5. **Disconnect cancels pending WHOX** — futures fail/resolve-empty rather than hanging forever. → Task 4 test invoking onDisconnect with pending call.

---

### Task 1: IRCv3 message tags in Message

**Files:**
- Modify: `library/Irc/Message.php` (parse + one property)
- Create: `tests/Irc/MessageTest.php`

**Interfaces:**
- Consumes: `Message::parse(string): ?Message`, `Message::__construct(string $command, array $args = [], ?string $prefix = null)`.
- Produces: `Message::$tags: ?array<string, string>` (public readonly-style public property, null when the line had no tag block); constructor gains optional trailing `?array $tags = null` (source-compatible).

- [ ] **Step 1: Write failing tests** — `tests/Irc/MessageTest.php` (namespace `Tests\Irc`):

```php
<?php
namespace Tests\Irc;

use Irc\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_untagged_line_parses_as_before(): void
    {
        $m = Message::parse(':nick!user@host PRIVMSG #chan :hello there');
        $this->assertSame('nick!user@host', $m->prefix);
        $this->assertSame('PRIVMSG', $m->command);
        $this->assertSame(['#chan', 'hello there'], $m->args);
        $this->assertNull($m->tags);
    }

    public function test_tags_parse_with_prefix_and_args(): void
    {
        $m = Message::parse('@account=zen;msgid=x1y2 :nick!user@host PRIVMSG #chan :hi');
        $this->assertSame(['account' => 'zen', 'msgid' => 'x1y2'], $m->tags);
        $this->assertSame('nick!user@host', $m->prefix);
        $this->assertSame('PRIVMSG', $m->command);
        $this->assertSame(['#chan', 'hi'], $m->args);
    }

    public function test_tags_without_prefix(): void
    {
        $m = Message::parse('@intent=ACTION PRIVMSG #chan :goes');
        $this->assertSame(['intent' => 'ACTION'], $m->tags);
        $this->assertNull($m->prefix);
        $this->assertSame(['#chan', 'goes'], $m->args);
    }

    public function test_valueless_and_empty_tag_values(): void
    {
        $m = Message::parse('@away;empty=;active=1 :s NOTICE * :x');
        $this->assertSame(['away' => '', 'empty' => '', 'active' => '1'], $m->tags);
    }

    public function test_tag_escapes_are_unescaped(): void
    {
        // \: -> ;, \s -> space, \\ -> backslash, \r, \n
        $m = Message::parse('@reply=a\\sb\\:c;d\\r\\ne :n!u@h PRIVMSG #c :x');
        $this->assertSame(['reply' => 'a b;c', 'd' => "\r\ne"], $m->tags);
    }

    public function test_lone_at_sign_is_not_a_tag_block(): void
    {
        // '@' followed by space is not valid tags; treat line as normal
        $m = Message::parse('@ :n!u@h PRIVMSG #c :x');
        $this->assertNotNull($m);
    }

    public function test_trailing_backslash_in_value(): void
    {
        $m = Message::parse('@k=v\\ :n!u@h PRIVMSG #c :x');
        $this->assertSame('v', $m->tags['k'] ?? 'v'); // dangling escape dropped, must not fatal
    }
}
```

(Adjust the two edge assertions to whatever the spec-correct behavior lands as — dangling escape drops the backslash; `@ ` alone degrades to command `@`. The test pins "does not fatal and stays consistent".)

- [ ] **Step 2: RED** — `vendor/bin/phpunit tests/Irc/MessageTest.php` fails (unknown property `$tags`).
- [ ] **Step 3: Implement** — in `Message.php`: add `public ?array $tags = null;` + constructor trailing param; in `parse()`, before the existing regex, strip a leading tag block:

```php
$tags = null;
if (str_starts_with($message, '@')) {
    $sp = strpos($message, ' ');
    if ($sp !== false) {
        $tagBlock = substr($message, 1, $sp - 1);
        $message = substr($message, $sp + 1);
        $tags = [];
        foreach (explode(';', $tagBlock) as $entry) {
            if ($entry === '') continue;
            [$k, $v] = array_pad(explode('=', $entry, 2), 2, null);
            if ($v === null) { $tags[$k] = ''; continue; }
            $tags[$k] = str_replace(
                ['\\\\', '\\:', '\\s', '\\r', '\\n', '\\'],
                ['\\', ';', ' ', "\r", "\n", ''],
                $v
            );
        }
    }
}
```

(order matters in the replace array: `\\\\` first so escaped backslashes survive; final `\\` drops any dangling escape). Pass `$tags` into the returned Message; `UNKNOWN` fallback unchanged. The existing regex is NOT modified.

- [ ] **Step 4: GREEN + full suite** (`vendor/bin/phpunit` — whole repo must stay green).
- [ ] **Step 5: phpstan** — `php -d memory_limit=1G vendor/bin/phpstan analyse library/Irc/Message.php tests/Irc/ --no-progress` — zero new errors.
- [ ] **Step 6: Commit** — `feat(irc): parse ircv3 message tags`

### Task 2: UserEvent::$account populated from tags

**Files:**
- Modify: `library/Irc/Event/UserEvent.php` (read first; ctor)
- Modify: `library/Irc/Client.php` — every user-event construction inside `handleMessage()` (PRIVMSG/chat, PM, NOTICE, JOIN, PART, QUIT, NICK, KICK)
- Test: `tests/Irc/AccountTagEventTest.php`

**Interfaces:**
- Consumes: `Message::$tags` (Task 1).
- Produces: `UserEvent::$account: ?string` (public readonly, optional trailing ctor param `?string $account = null`); helper on Client (private, per-site inline is fine): `$account = ($message->tags['account'] ?? null) === '*' ? null : ($message->tags['account'] ?? null);` — compute once per message at the top of the relevant cases.

- [ ] **Step 1: Failing tests** — harness subclass:

```php
final class ClientHarness extends \Irc\Client {
    public function exposeHandleMessage(\Irc\Event\MessageEvent $e): void { $this->handleMessage($e); }
    public array $emitted = [];
    // constructor: reuse parent (nick, server, log, port, bindIp, ssl)
    protected function emit(string $event, \Irc\Event\Event $ev): void { $this->emitted[$event] = $ev; }
}
```

(Check the real `emit()` signature in EventEmitter.php first — override however it's actually declared; if it's not overridable, subscribe via `on()` instead and capture events that way. Craft `MessageEvent(time, 'message', $harness, Message::parse('@account=zen :nick!u@h PRIVMSG #chan :hi'), raw)` — check MessageEvent's real ctor.) Tests: chat with account tag → `ChatEvent->account === 'zen'`; without tag → null; `@account=*` → null; PM path (PmEvent) and NOTICE path (NoticeEvent) same three; NICK/PART/QUIT/KICK events carry account when tagged. If some of those events are emitted outside handleMessage, test what handleMessage emits and note any gaps in the report.

- [ ] **Step 2: RED** → **Step 3: Implement** (add the param to UserEvent; populate at each site) → **Step 4: GREEN + full suite** → **Step 5: phpstan scoped** → **Step 6: Commit** — `feat(irc): surface account-tag on user events`

### Task 3: CAP requests + extended-join on JoinEvent

**Files:**
- Modify: `library/Irc/Client.php` (CAP LS case: conditional REQs; CMD_JOIN case: 3-param form)
- Modify: `library/Irc/Event/JoinEvent.php` (`?string $account = null`, `?string $realname = null` optional trailing params)
- Test: `tests/Irc/CapAndExtendedJoinTest.php` (same harness pattern)

- [ ] **Step 1: Failing tests**:
  - CAP LS advertising `multi-prefix account-tag extended-join` (no sasl) → sent lines include `CAP REQ :account-tag` and `CAP REQ :extended-join` (capture `send()` via an override exposing `$this->sendQ` — sendQ is public) and CAP END still sent once at the end.
  - CAP LS with only `multi-prefix` → NO new REQs, CAP END still sent.
  - Extended JOIN `:zen!~z@h JOIN #chan zenith :Real Name` → JoinEvent account `zenith`, realname `Real Name`; account `*` → null; old-form `JOIN #chan` → both null.
- [ ] **Step 2: RED** → **Step 3: Implement**: in the CAP LS block add the two conditional REQs (mirror the multi-prefix pattern, setting `$req = true` when either is requested so the existing `if($req && !$this->waitOnSasl) CAP END` still fires); in CMD_JOIN, when `$message->getArg(2) !== null` parse `arg(1)` as account (normalize `*`→null) and `arg(2)` as realname, passing them (plus the tag account as fallback when extended-join params absent) into the JoinEvent construction site(s) — read the existing JoinEvent emission fully first (self-join vs other-join paths if split).
- [ ] **Step 4: GREEN + full suite + scoped phpstan** → **Step 5: Commit** — `feat(irc): request account-tag and extended-join caps, parse extended joins`

### Task 4: WHOX (354/315) with Future API

**Files:**
- Modify: `library/Irc/Consts.php` (`const RPL_WHOSPCRPL = '354';`)
- Modify: `library/Irc/Client.php` (new API + numeric handling)
- Test: `tests/Irc/WhoxTest.php`

**Interfaces:**
- Produces: `Client::whox(string $target, string $fields = 'uhnaf'): \Amp\Future` where the Future resolves with `list<array<string, mixed>>` — entries keyed by requested field letter (`u` user, `h` host, `n` nick, `a` account with `*`→null, `f` flags, plus pass-through for `c,i,s,d,r,l,t` using the same letter keys). Invalid/empty `$fields` throws `InvalidArgumentException`. Target with spaces rejected likewise.

- [ ] **Step 1: Failing tests** (harness + `Amp\async`/`Revolt\EventLoop` where needed, or `Future->isComplete()` polling after feeding messages):
  - `whox('#chan')` sends `WHO #chan %uhnaf,<label>` where label is unique per call (two calls → different labels; assert via sendQ).
  - feeding `:srv 354 me <label> ident host nick flags account` then `:srv 315 me #chan :End of WHO` resolves the Future with `[['u'=>..., 'h'=>..., 'n'=>..., 'f'=>..., 'a'=>...]]`, account `*`→null, field order per request string.
  - two concurrent whox calls with interleaved 354s resolve to their own entries; a 354 carrying an unknown label is ignored without error.
  - 315 for an untracked target is ignored (no error) — existing 315 flow (if any) unchanged.
  - timeout: with `Client::$whoxTimeout` (protected, seconds, default 15) set to 0 for the test, the Future resolves with the entries received so far (or empty list) — pick resolve-with-partial semantics and pin it.
  - `onDisconnect()` with a pending call → Future resolves empty (not hung).
- [ ] **Step 2: RED** → **Step 3: Implement**: pending map `protected array $whoxPending = [];` keyed by label → `['future' => \Amp\Future, 'suspension' => \Amp\Deferred, 'target' => string, 'fields' => string, 'timer' => string]` (use `Amp\Deferred`); label generation `substr(bin2hex(random_bytes(6)), 0, 8)`; send `WHO <target> %<fields>,<label>`; add `case RPL_WHOSPCRPL:` and extend the existing 315 handling (or add if absent) in handleMessage: 354 args are `[me, label, ...fields]` — map `str_split($fields)` to `array_slice($args, 2)`; 315 arg(1) = target → resolve+cancel timer for every pending entry whose target matches (case-insensitive), then continue to existing behavior. Timeout via `EventLoop::delay($this->whoxTimeout, ...)` resolve-partial; `onDisconnect()` resolves all pending empty. Null-safe: tags from Task 1 irrelevant here.
- [ ] **Step 4: GREEN + full suite + scoped phpstan** → **Step 5: Commit** — `feat(irc): whox api with label correlated futures`

### Task 5: spec update + wrap

- [ ] Mark foundation step 2 complete in `docs/superpowers/specs/2026-09-26-user-system-design.md` (note: rebinding detection lives in engines layer, WHOX API + caps shipped). Commit `docs: mark user system foundation step 2 (irc client) complete` and push only after the final whole-branch review (controller does this).
