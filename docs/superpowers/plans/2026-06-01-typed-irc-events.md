# Typed IRC Event Objects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all anonymous stdClass IRC event args with a typed class hierarchy, eliminating 481 `property.notFound` PHPStan errors and making the event system type-safe.

**Architecture:** Create event classes in `library/Irc/Event/`. Each emit site in `Client.php` constructs the correct typed object. `EventEmitter::emit()` accepts and dispatches typed `Event` objects instead of arrays. All ~35 consumer files update callback type hints and property names.

**Tech Stack:** PHP 8.1 readonly properties, PSR-4 autoloading, PHPStan level 6 verification (no test framework configured).

**Spec:** `docs/superpowers/specs/2026-06-01-typed-irc-events-design.md`

---

### Task 1: Add PSR-4 autoloading for Irc\Event namespace

**Files:**
- Modify: `composer.json`
- Modify: `phpstan.neon` (if needed for new paths)

- [ ] **Step 1: Add autoloading entry to composer.json**

In `composer.json`, add `"Irc\\Event\\": "library/Irc/Event"` to the `autoload.psr-4` section:

```json
"autoload": {
    "psr-4": {
        "scripts\\": "scripts",
        "Irc\\": "library/Irc",
        "Irc\\Event\\": "library/Irc/Event",
        "draw\\": "library/draw",
        "lolbot\\entities\\": "entities",
        "lolbot\\cli_cmds\\": "cli_cmds"
    },
```

- [ ] **Step 2: Regenerate autoloader**

Run: `composer dump-autoload`

- [ ] **Step 3: Create the Event directory**

Run: `mkdir -p library/Irc/Event`

- [ ] **Step 4: Commit**

```bash
git add composer.json library/Irc/Event
git commit -m "Add PSR-4 autoloading for Irc\\Event namespace"
```

---

### Task 2: Create the Event base class and NamesReply/ListEntry support classes

**Files:**
- Create: `library/Irc/Event/Event.php`
- Create: `library/Irc/Event/NamesReply.php`
- Create: `library/Irc/Event/ListEntry.php`

- [ ] **Step 1: Create Event.php (abstract base)**

```php
<?php

namespace Irc\Event;

abstract class Event
{
    public readonly int $time;
    public string $event;
    public readonly \Irc\EventEmitter $sender;

    public function __construct(int $time, string $event, \Irc\EventEmitter $sender)
    {
        $this->time = $time;
        $this->event = $event;
        $this->sender = $sender;
    }
}
```

- [ ] **Step 2: Create NamesReply.php**

This is a mutable builder — Client.php accumulates names across multiple RPL_NAMREPLY lines, then passes the final object to NamesEvent.

```php
<?php

namespace Irc\Event;

class NamesReply
{
    public string $nick = '';
    public string $channelType = '';
    public string $chan = '';
    public array $names = [];
}
```

- [ ] **Step 3: Create ListEntry.php**

```php
<?php

namespace Irc\Event;

class ListEntry
{
    public function __construct(
        public readonly string $chan,
        public readonly string $userCount,
        public readonly string $topic,
    ) {}
}
```

- [ ] **Step 4: Commit**

```bash
git add library/Irc/Event/Event.php library/Irc/Event/NamesReply.php library/Irc/Event/ListEntry.php
git commit -m "Create Event base class, NamesReply, and ListEntry"
```

---

### Task 3: Create the UserEvent base and all user-related event classes

**Files:**
- Create: `library/Irc/Event/UserEvent.php`
- Create: `library/Irc/Event/ChatEvent.php`
- Create: `library/Irc/Event/PmEvent.php`
- Create: `library/Irc/Event/NoticeEvent.php`
- Create: `library/Irc/Event/ModeEvent.php`
- Create: `library/Irc/Event/ChannelModeIsEvent.php`
- Create: `library/Irc/Event/QuitEvent.php`
- Create: `library/Irc/Event/JoinEvent.php`
- Create: `library/Irc/Event/PartEvent.php`
- Create: `library/Irc/Event/KickEvent.php`
- Create: `library/Irc/Event/NickEvent.php`

- [ ] **Step 1: Create UserEvent.php (abstract)**

```php
<?php

namespace Irc\Event;

abstract class UserEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly string $nick,
        public readonly string $ident,
        public readonly string $host,
        public readonly string $identhost,
        public readonly string $fullhost,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 2: Create ChatEvent.php**

```php
<?php

namespace Irc\Event;

class ChatEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $chan,
        public readonly string $text,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 3: Create PmEvent.php**

```php
<?php

namespace Irc\Event;

class PmEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $to,
        public readonly string $text,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 4: Create NoticeEvent.php**

Same shape as PmEvent but named NoticeEvent for type clarity:

```php
<?php

namespace Irc\Event;

class NoticeEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $to,
        public readonly string $text,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 5: Create ModeEvent.php**

```php
<?php

namespace Irc\Event;

class ModeEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $target,
        public readonly array $args,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 6: Create ChannelModeIsEvent.php**

```php
<?php

namespace Irc\Event;

class ChannelModeIsEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $chan,
        public readonly array $args,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 7: Create QuitEvent.php**

```php
<?php

namespace Irc\Event;

class QuitEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $text,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 8: Create JoinEvent.php**

```php
<?php

namespace Irc\Event;

class JoinEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $chan,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 9: Create PartEvent.php**

Same shape as JoinEvent:

```php
<?php

namespace Irc\Event;

class PartEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $chan,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 10: Create KickEvent.php**

`$nick` is the kicker (from prefix), `$target` is the kickee (from arg 1).

```php
<?php

namespace Irc\Event;

class KickEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $chan,
        public readonly string $target,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 11: Create NickEvent.php**

```php
<?php

namespace Irc\Event;

class NickEvent extends UserEvent
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        string $nick,
        string $ident,
        string $host,
        string $identhost,
        string $fullhost,
        public readonly string $old,
        public readonly string $new,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
```

- [ ] **Step 12: Commit**

```bash
git add library/Irc/Event/
git commit -m "Create UserEvent base and all user-related event classes"
```

---

### Task 4: Create remaining event classes (non-user)

**Files:**
- Create: `library/Irc/Event/NamesEvent.php`
- Create: `library/Irc/Event/ListEvent.php`
- Create: `library/Irc/Event/PongEvent.php`
- Create: `library/Irc/Event/OptionsEvent.php`
- Create: `library/Irc/Event/NumericEvent.php`
- Create: `library/Irc/Event/MessageEvent.php`
- Create: `library/Irc/Event/SentEvent.php`
- Create: `library/Irc/Event/WelcomeEvent.php`
- Create: `library/Irc/Event/DisconnectedEvent.php`

- [ ] **Step 1: Create NamesEvent.php**

```php
<?php

namespace Irc\Event;

class NamesEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly string $chan,
        public readonly NamesReply $names,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 2: Create ListEvent.php**

```php
<?php

namespace Irc\Event;

class ListEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly array $items,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 3: Create PongEvent.php**

```php
<?php

namespace Irc\Event;

class PongEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly ?string $arg,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 4: Create OptionsEvent.php**

```php
<?php

namespace Irc\Event;

class OptionsEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly array $options,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 5: Create NumericEvent.php**

```php
<?php

namespace Irc\Event;

class NumericEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly \Irc\Message $message,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 6: Create MessageEvent.php (internal plumbing)**

```php
<?php

namespace Irc\Event;

class MessageEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly \Irc\Message $message,
        public readonly string $raw,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 7: Create SentEvent.php (internal plumbing)**

```php
<?php

namespace Irc\Event;

class SentEvent extends Event
{
    public function __construct(
        int $time,
        string $event,
        \Irc\EventEmitter $sender,
        public readonly \Irc\Message $message,
    ) {
        parent::__construct($time, $event, $sender);
    }
}
```

- [ ] **Step 8: Create WelcomeEvent.php**

```php
<?php

namespace Irc\Event;

class WelcomeEvent extends Event
{
}
```

- [ ] **Step 9: Create DisconnectedEvent.php**

```php
<?php

namespace Irc\Event;

class DisconnectedEvent extends Event
{
}
```

- [ ] **Step 10: Commit**

```bash
git add library/Irc/Event/
git commit -m "Create remaining event classes (Names, List, Pong, Options, Numeric, Message, Sent, Welcome, Disconnected)"
```

---

### Task 5: Update EventEmitter to accept typed Event objects

**Files:**
- Modify: `library/Irc/EventEmitter.php`

- [ ] **Step 1: Update emit() signature and body**

Read the current `library/Irc/EventEmitter.php`. Replace the `emit()` method to accept `?Event` instead of `array`. The key changes:

1. Signature: `array $args = []` becomes `?Event $args = null`
2. No more `(object)$args` cast — pass the Event directly
3. `time`/`event`/`sender` are set by the caller in the Event constructor, not by emit()
4. For compound events, overwrite `$args->event` (the only mutable field) per split
5. When `$args` is null, create a bare `Event` — but since Event is abstract, use a check and create a simple concrete class or throw. Since `welcome` and `disconnected` are the only events emitted without args, they'll pass `WelcomeEvent`/`DisconnectedEvent`. So null case just creates a generic Event... but Event is abstract. The solution: emit() requires an Event, never null. Update all emit sites to pass an Event object.

Updated emit method:

```php
/**
 * @param array<string, mixed>|Event $args
 */
public function emit(string $event, array|Event $args = []): static
{
    if (str_contains($event, ',')) {
        $events = explode(',', $event);
        foreach ($events as $ev) {
            $this->emit(trim($ev), $args);
        }
        return $this;
    }

    if ($args instanceof Event) {
        $args->event = $event;
        $evtObj = $args;
    } else {
        $args['time'] = time();
        $args['event'] = $event;
        $args['sender'] = $this;
        $evtObj = (object)$args;
    }

    if (!empty($this->onceEventCallbacks[$event])) {
        foreach ($this->onceEventCallbacks[$event] as $callback)
            call_user_func($callback, $evtObj, $this);
        $this->onceEventCallbacks[$event] = array();
    }

    if (!empty($this->eventCallbacks[$event])) {
        foreach ($this->eventCallbacks[$event] as $callback)
            call_user_func($callback, $evtObj, $this);
    }

    return $this;
}
```

This transitional approach accepts both `array` (old style) and `Event` (new style), so emit sites can be migrated one at a time without breaking everything at once.

- [ ] **Step 2: Verify PHPStan still passes**

Run: `composer phpstan`
Expected: Same errors as before (no new errors introduced by the union type).

- [ ] **Step 3: Commit**

```bash
git add library/Irc/EventEmitter.php
git commit -m "Update EventEmitter to accept both array and Event objects (transitional)"
```

---

### Task 6: Migrate Client.php emit sites to typed events

**Files:**
- Modify: `library/Irc/Client.php`

This is the core change. Each `$this->emit(...)` call in Client.php switches from passing an array to constructing the appropriate Event class. Read the current file to find all emit sites (~20 calls).

The `Client` class is in namespace `Irc`, so Event classes are accessed as `Event\ChatEvent`, etc.

**Property mapping for emit sites:**

| Event Name | Event Class | Properties from current array |
|------------|-------------|-------------------------------|
| `message, message:{cmd}` | `MessageEvent` | `$msg, $message` (raw string) |
| `sent, sent:{cmd}` | `SentEvent` | `$message` |
| `pong` | `PongEvent` | `$message->getArg(1)` |
| `join, join:...` | `JoinEvent` | `$message->nick`, `$message->name`, `$message->host`, `$message->getIdentHost()`, `$message->getHostString()`, `$channel` |
| `part, part:...` | `PartEvent` | same as join |
| `kick, kick:...` | `KickEvent` | kicker from prefix, `$channel`, `$nick` (kickee from arg 1 becomes `$target`) |
| `notice, notice:...` | `NoticeEvent` | `$from`, `$message->nick`, `$message->name`, `$message->host`, `$message->getIdentHost()`, `$message->getHostString()`, `$to`, `$text` |
| `mode` | `ModeEvent` | `$from`, `$message->nick`, `$message->name`, `$message->host`, `$message->getHostString()`, `$message->getArg(0)` as `$target`, `array_splice($message->args, 1)` as `$args` |
| `324` (RPL_CHANNELMODEIS) | `ChannelModeIsEvent` | user fields from message, `$message->getArg(1)` as `$chan`, `array_splice($message->args, 2)` as `$args` |
| `chat, chat:...` | `ChatEvent` | `$from`, `$message->nick`, `$message->name`, `$message->host`, `$message->getIdentHost()`, `$message->getHostString()`, `$to` as `$chan`, `$text` |
| `pm, pm:...` | `PmEvent` | user fields, `$to`, `$text` |
| `names, names:...` | `NamesEvent` | `$this->namesReply`, `$channel` |
| `list` | `ListEvent` | `$this->listReply` (convert stdClass to ListEntry) |
| `welcome` | `WelcomeEvent` | no args |
| `options` | `OptionsEvent` | `$this->options` |
| `nick` | `NickEvent` | `$message->nick` as user fields, `$message->nick` as `$old`, `$newNick` as `$new` |
| `quit` | `QuitEvent` | user fields, `$message->getArg(0)` as `$text` |
| default numeric | `NumericEvent` | `$message` |

Also update:
- `$this->namesReply` — change from `new \stdClass()` to `new Event\NamesReply()`
- `$this->listReply` — change stdClass entries to `new Event\ListEntry(...)` objects

**Important:** The `time` and `sender` are no longer set by `emit()`. Each emit site passes `time: time()` and `sender: $this`. Note that `Client extends EventEmitter`, so `$this` IS the sender.

- [ ] **Step 1: Add `use Irc\Event` imports at top of Client.php**

After the existing `require_once 'Consts.php';`, add imports for all event classes used. Since they're in the `Irc` namespace, reference as `Event\ChatEvent`, etc.

- [ ] **Step 2: Migrate all emit sites**

Convert each `$this->emit("event", array(...))` to `$this->emit("event", new Event\XxxEvent(...))`. Use the property mapping table above. Each constructor call passes `time()` and `$this` as the first two args.

- [ ] **Step 3: Update namesReply accumulation**

Replace `new \stdClass()` at line ~882 with `new Event\NamesReply()`. The property names match (`nick`, `channelType`, `channel` → `chan`, `names`). Update `$this->namesReply->channel` to `$this->namesReply->chan`.

- [ ] **Step 4: Update listReply construction**

At line ~908, replace `(object)array(...)` with `new Event\ListEntry(...)`.

- [ ] **Step 5: Verify PHPStan**

Run: `composer phpstan`
Expected: May have errors from consumers still expecting stdClass properties — those get fixed in later tasks.

- [ ] **Step 6: Commit**

```bash
git add library/Irc/Client.php
git commit -m "Migrate Client.php emit sites to typed Event objects"
```

---

### Task 7: Remove array support from EventEmitter

**Files:**
- Modify: `library/Irc/EventEmitter.php`

- [ ] **Step 1: Remove the array branch from emit()**

Now that all emit sites use typed Events, remove the `array` branch from emit(). The signature becomes:

```php
public function emit(string $event, ?Event $eventObj = null): static
{
    if (str_contains($event, ',')) {
        $events = explode(',', $event);
        foreach ($events as $ev) {
            $this->emit(trim($ev), $eventObj);
        }
        return $this;
    }

    if ($eventObj === null) {
        $eventObj = new class(time(), $event, $this) extends Event {};
    }
    $eventObj->event = $event;

    if (!empty($this->onceEventCallbacks[$event])) {
        foreach ($this->onceEventCallbacks[$event] as $callback)
            call_user_func($callback, $eventObj, $this);
        $this->onceEventCallbacks[$event] = array();
    }

    if (!empty($this->eventCallbacks[$event])) {
        foreach ($this->eventCallbacks[$event] as $callback)
            call_user_func($callback, $eventObj, $this);
    }

    return $this;
}
```

Add the `use Irc\Event\Event;` import at the top.

- [ ] **Step 2: Verify PHPStan**

Run: `composer phpstan`

- [ ] **Step 3: Commit**

```bash
git add library/Irc/EventEmitter.php
git commit -m "Remove array support from EventEmitter, require Event objects"
```

---

### Task 8: Update library consumers — Nicks.php

**Files:**
- Modify: `library/Nicks.php`

This file has many `->on()` callbacks accessing `$args` properties for different event types.

**Changes needed:**
1. Add `use Irc\Event\ChatEvent`, `JoinEvent`, `PartEvent`, `KickEvent`, `NickEvent`, `QuitEvent`, `ModeEvent`, `NamesEvent`, `PmEvent`, `UserEvent` imports
2. Type-hint each callback's `$args` parameter with the correct event class
3. `$args->channel` → `$args->chan` (lines 65, 71, 73, 77, 81, 83, 89, 91)
4. `$args->target` → stays `$args->target` (ModeEvent, lines 128-154) — ModeEvent has `$target` property
5. `$args->on` → `$args->target` (line 99, mode handler checks if target starts with #) — ModeEvent uses `$target` instead of old `$on`
6. `$args->from` → `$args->nick` (line 94, pm handler) — PmEvent has `$nick`
7. `mode()` method signature: change `$args` type from `object` to `ModeEvent`
8. `names()` method signature: `$names` param type from `object` to `NamesReply`
9. Kick handler: `$args->nick` was the kickee, now `$args->target` is the kickee and `$args->nick` is the kicker. The kick handler at line ~91 calls `$this->kick($args->nick, $args->channel)` — need to change to `$this->kick($args->target, $args->chan)`.

- [ ] **Step 1: Update all callback type hints and property accesses in Nicks.php**

- [ ] **Step 2: Verify PHPStan for Nicks.php specifically**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse -l 6 --error-format=raw 2>&1 | grep Nicks.php`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add library/Nicks.php
git commit -m "Update Nicks.php to use typed event classes"
```

---

### Task 9: Update library consumers — Channels.php

**Files:**
- Modify: `library/Channels.php`

**Changes needed:**
1. Add event class imports
2. Type-hint all `->on()` callbacks:
   - `'join'` → `JoinEvent $args`
   - `'names'` → `NamesEvent $args`
   - `'part'` → `PartEvent $args`
   - `'quit'` → `QuitEvent $args`
   - `'kick'` → `KickEvent $args`
   - `'mode'` → `ModeEvent $args`
   - `'324'` → `ChannelModeIsEvent $args`
   - `'332'`, `'333'` → `NumericEvent $args`
   - `'nick'` → `NickEvent $args`
3. `$args->channel` → `$args->chan` (all occurrences)
4. `$args->on` → `$args->target` (mode handler, line 75)
5. `$args->args` → stays `$args->args` (ModeEvent and ChannelModeIsEvent both have `$args`)
6. Kick handler: `$args->nick` was kickee → now `$args->target` is kickee
7. Nick handler: `$args->old` and `$args->new` stay the same (NickEvent has those)
8. `processModes()` already has typed params from previous work — no change needed there

- [ ] **Step 1: Update all callback type hints and property accesses**

- [ ] **Step 2: Verify PHPStan for Channels.php**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse -l 6 --error-format=raw 2>&1 | grep Channels.php`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add library/Channels.php
git commit -m "Update Channels.php to use typed event classes"
```

---

### Task 10: Update lolbot.php consumers

**Files:**
- Modify: `lolbot.php`

**Changes needed:**
1. Add event class imports (`ChatEvent`, `PmEvent`, `KickEvent`, `ModeEvent`, `WelcomeEvent`)
2. Type-hint callbacks:
   - `'welcome'` → `WelcomeEvent $e`
   - `'kick'` → `KickEvent $args`
   - `'mode'` → `ModeEvent $args`
   - `'chat'` → `ChatEvent $args`
   - `'pm'` → `PmEvent $args`
3. `$args->channel` → `$args->chan`
4. `$args->from` → `$args->nick`
5. `$args->on` → `$args->target` (mode handler)
6. Kick handler: `$args->nick` was kickee → `$args->target`

- [ ] **Step 1: Update all callback type hints and property accesses**

- [ ] **Step 2: Verify PHPStan for lolbot.php**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse -l 6 --error-format=raw 2>&1 | grep lolbot.php`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add lolbot.php
git commit -m "Update lolbot.php to use typed event classes"
```

---

### Task 11: Update artbots.php and NetworkContext.php consumers

**Files:**
- Modify: `artbots.php`
- Modify: `NetworkContext.php`

**artbots.php changes:**
1. Add event class imports
2. Type-hint callbacks:
   - `'welcome'` → `WelcomeEvent $e`
   - `'kick'` → `KickEvent $args`
   - `ERR_CANNOTSENDTOCHAN` → `NumericEvent $args`
   - `'mode'` → `ModeEvent $args`
   - `'chat'` → `ChatEvent $args`
3. `$args->channel` → `$args->chan`
4. `$args->from` → `$args->nick`
5. `$args->on` → `$args->target` (mode handler)
6. Kick handler: `$args->nick` was kickee → `$args->target`

**NetworkContext.php changes:**
1. Type-hint the `'chat'` callback as `ChatEvent $args`
2. `$args->from` → `$args->nick`
3. `$args->channel` → `$args->chan`
4. `$args->chan` stays as `$args->chan`

- [ ] **Step 1: Update artbots.php**

- [ ] **Step 2: Update NetworkContext.php**

- [ ] **Step 3: Verify PHPStan for both files**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse -l 6 --error-format=raw 2>&1 | grep -E '(artbots|NetworkContext)\.php'`
Expected: 0 errors

- [ ] **Step 4: Commit**

```bash
git add artbots.php NetworkContext.php
git commit -m "Update artbots.php and NetworkContext.php to use typed event classes"
```

---

### Task 12: Update artbot_scripts/ consumers

**Files:**
- Modify: `artbot_scripts/art-common.php`
- Modify: `artbot_scripts/quotes.php`
- Modify: `artbot_scripts/urlimg.php`
- Modify: `artbot_scripts/drawing.php`
- Modify: `artbot_scripts/bashorg.php`
- Modify: `artbot_scripts/artfart.php`
- Modify: `artbot_scripts/help.php`

All cmdr handlers in these files receive `ChatEvent $args` (triggered from chat). The `$args->chan` property stays the same. Changes:

1. Change `object $args` to `\Irc\Event\ChatEvent $args` in all handler function signatures
2. `$args->from` → `$args->nick` (in quotes.php line 175, art-common.php line 112)
3. `$args->channel` → `$args->chan` (artbots.php line 149 already uses `$args->channel` — change to `$args->chan`)
4. For `quotes.php` — the `'chat'` direct listener (line 174) needs `ChatEvent $args` type hint

The `$args` param in `reqart()` was already removed, so only the other functions need updating.

- [ ] **Step 1: Update all artbot_scripts/ files**

Use grep to find every `object $args` in `artbot_scripts/` and change to `\Irc\Event\ChatEvent $args`. Also find and fix any `$args->from` or `$args->channel` references.

- [ ] **Step 2: Verify PHPStan for artbot_scripts/**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse -l 6 --error-format=raw 2>&1 | grep artbot_scripts`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/
git commit -m "Update artbot_scripts/ to use ChatEvent type"
```

---

### Task 13: Update scripts/ consumers (batch 1 — direct event listeners)

**Files:**
- Modify: `scripts/seen/seen.php`
- Modify: `scripts/tell/tell.php`
- Modify: `scripts/bomb_game/bomb_game.php`

These scripts register `->on()` callbacks directly (not via cmdr). They need specific event types:

**seen.php:**
- `'notice'` callback → `NoticeEvent $args`
- `'chat'` callback → `ChatEvent $args`
- `$args->from` → `$args->nick`

**tell.php:**
- `'chat'` callback → `ChatEvent $args`
- `$args->from` → `$args->nick`

**bomb_game.php:**
- `'nick'` callback → `NickEvent $args`
- `$args->old` / `$args->new` stay the same

- [ ] **Step 1: Update seen.php, tell.php, bomb_game.php**

- [ ] **Step 2: Verify PHPStan**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse -l 6 --error-format=raw 2>&1 | grep -E '(seen|tell|bomb_game)'`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add scripts/seen/seen.php scripts/tell/tell.php scripts/bomb_game/bomb_game.php
git commit -m "Update seen, tell, bomb_game to use typed event classes"
```

---

### Task 14: Update scripts/ consumers (batch 2 — cmdr handlers)

**Files:**
- Modify all scripts that have cmdr handler functions with `object $args` parameter

These all receive ChatEvent. Search for `object $args` across `scripts/` and change each to `\Irc\Event\ChatEvent $args`. Files likely include (grep to confirm):
- `scripts/weather/weather.php`
- `scripts/linktitles/linktitles.php`
- `scripts/lastfm/lastfm.php`
- `scripts/codesand/codesand.php`
- `scripts/github/github.php`
- `scripts/tools/tools.php`
- `scripts/stocks/stocks.php`
- `scripts/mal/mal.php`
- `scripts/urbandict/urbandict.php`
- `scripts/wiki/wiki.php`
- `scripts/owncast/owncast.php`
- `scripts/notifier/notifier.php`
- `scripts/help/help.php`
- `scripts/alias/alias.php`
- `scripts/remindme/remindme.php`
- `scripts/translate/translate.php`
- `scripts/invidious/invidious.php`
- `scripts/youtube/youtube.php`
- `scripts/wolfram/wolfram.php`
- `scripts/brave.php`
- `scripts/yoda.php`
- `scripts/bing.php`

Also fix:
- `$args->from` → `$args->nick`
- `$args->channel` → `$args->chan`
- Any `$args->host` or `$args->identhost` accesses (now on ChatEvent from UserEvent)

**codesand.php and NetworkContext.php special case:** These have `canRun()` methods that access `$args->channel` → `$args->chan`.

- [ ] **Step 1: Grep for all `object $args` in scripts/ and update to `\Irc\Event\ChatEvent $args`**

- [ ] **Step 2: Grep for all `$args->from` and `$args->channel` in scripts/ and update**

- [ ] **Step 3: Verify PHPStan**

Run: `composer phpstan`
Expected: 0 errors at level 6

- [ ] **Step 4: Commit**

```bash
git add scripts/
git commit -m "Update all scripts/ to use ChatEvent type and unified property names"
```

---

### Task 15: Final verification and cleanup

- [ ] **Step 1: Run full PHPStan at level 6**

Run: `composer phpstan`
Expected: 0 errors

- [ ] **Step 2: Run PHPStan at level 9 to measure improvement**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse -l 9 2>&1 | grep 'Found.*error'`
Expected: Dramatically fewer than the original 1000+. The `property.notFound` errors should be eliminated.

- [ ] **Step 3: Remove any unused imports or dead code introduced during migration**

- [ ] **Step 4: Final commit if needed**

```bash
git add -A
git commit -m "Final cleanup after typed event migration"
```
