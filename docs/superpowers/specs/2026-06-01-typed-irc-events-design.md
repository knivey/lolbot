# Typed IRC Event Objects

## Problem

The IRC library (`library/Irc/`) emits events using anonymous `stdClass` objects created by casting associative arrays to `(object)`. All event handlers receive an untyped `$args` parameter with dynamic property access (`$args->chan`, `$args->nick`, etc.). This causes 481 `property.notFound` errors at PHPStan level 9 and makes the codebase harder to navigate and refactor safely.

## Goal

Replace anonymous stdClass event args with a typed class hierarchy. Every event gets a concrete class with declared readonly properties. All consumer code is updated to use typed properties. No deprecation period — everything is completed in one pass.

## Approach

**Factory in emit sites (Approach A):** Each emit site in `Client.php` constructs the correct event class directly. `EventEmitter::emit()` receives and dispatches the typed object. No array-to-object conversion, no registry, no magic.

## Class Hierarchy

All classes live in `library/Irc/Event/` under the `Irc\Event` namespace.

```
Irc\Event\Event (abstract base)
    public readonly int $time
    public string $event          (mutable — set by emit() for compound events)
    public readonly EventEmitter $sender

    Irc\Event\UserEvent (abstract extends Event)
        public readonly string $nick
        public readonly string $ident
        public readonly string $host
        public readonly string $identhost
        public readonly string $fullhost

        Irc\Event\ChatEvent
            public readonly string $chan
            public readonly string $text

        Irc\Event\PmEvent
            public readonly string $to
            public readonly string $text

        Irc\Event\NoticeEvent
            public readonly string $to
            public readonly string $text

        Irc\Event\ModeEvent
            public readonly string $target
            public readonly array $args

        Irc\Event\ChannelModeIsEvent
            public readonly string $chan
            public readonly array $args

        Irc\Event\QuitEvent
            public readonly string $text

        Irc\Event\JoinEvent
            public readonly string $chan

        Irc\Event\PartEvent
            public readonly string $chan

        Irc\Event\KickEvent
            public readonly string $chan
            public readonly string $target    (the kickee)

        Irc\Event\NickEvent
            public readonly string $old       (previous nick, from prefix)
            public readonly string $new       (new nick, from arg 0)

    Irc\Event\NamesEvent extends Event
        public readonly string $chan
        public readonly NamesReply $names

    Irc\Event\ListEvent extends Event
        public readonly array $items          (array<string, ListEntry>)

    Irc\Event\PongEvent extends Event
        public readonly ?string $arg

    Irc\Event\OptionsEvent extends Event
        public readonly array $options

    Irc\Event\NumericEvent extends Event
        public readonly Message $message

    Irc\Event\WelcomeEvent extends Event
        (marker — no extra properties)

    Irc\Event\DisconnectedEvent extends Event
        (marker — no extra properties)
```

### Supporting Classes

```
Irc\Event\NamesReply
    public string $nick
    public string $channelType
    public string $chan
    public array $names = []
    (mutable during accumulation in Client.php, passed to NamesEvent when complete)

Irc\Event\ListEntry
    public readonly string $chan
    public readonly string $userCount
    public readonly string $topic
```

### Key Design Decisions

- `$event` on the base Event is the only mutable public field. It is set/overwritten by `EventEmitter::emit()` when splitting compound event names like `"chat, chat:$to"`. All other fields are readonly.
- `$nick` on UserEvent always means "who performed the action" (from the IRC message prefix). For KickEvent, `$nick` is the kicker; the kickee is `$target`.
- JoinEvent, PartEvent, KickEvent, and NickEvent extend UserEvent because the IRC prefix provides full user info (nick, ident, host). The current code only extracts `$nick` and `$identhost` — this refactor adds the missing fields.
- All numeric IRC replies share a single `NumericEvent` class. No per-numeric classes.
- `$chan` is used instead of `$channel` as the property name (matches existing dominant usage).

## Property Cleanup

The following property name changes are applied across all consumers. No aliases, no deprecation — direct rename.

| Event | Old Property | New Property | Notes |
|-------|-------------|--------------|-------|
| ChatEvent | `$args->channel` | `$args->chan` | `channel` dropped, `chan` is primary |
| ChatEvent | `$args->from` | `$args->nick` | `from` was always identical to `nick` |
| ChatEvent | `$args->target` | `$args->chan` | redundant alias |
| PmEvent | `$args->from` | `$args->nick` | |
| PmEvent | `$args->target` | `$args->to` | |
| NoticeEvent | `$args->from` | `$args->nick` | |
| NoticeEvent | `$args->target` | `$args->to` | |
| ModeEvent | `$args->on` | `$args->target` | renamed for clarity |
| QuitEvent | `$args->msg` | `$args->text` | unified naming |
| KickEvent | `$args->nick` (kickee) | `$args->target` | `$args->nick` now = kicker from prefix |
| JoinEvent | n/a | `$args->ident`, `$args->host`, `$args->fullhost` | new fields |
| PartEvent | n/a | same new user fields | new fields |
| KickEvent | n/a | same new user fields | new fields |
| NickEvent | n/a | `$args->ident`, `$args->host`, `$args->fullhost` | new fields |

## EventEmitter API Change

### Before
```php
public function emit(string $event, array $args = []): static
// casts array to (object), passes to callbacks
```

### After
```php
public function emit(string $event, ?Event $args = null): static
// passes Event directly to callbacks
// if null, creates a bare Event with just time/event/sender
// overwrites $args->event for each part of compound event names
```

### Callback Signature Change
All `->on()` callbacks gain type hints:
```php
// Before
$bot->on('chat', function($args, $bot) { ... });

// After
$bot->on('chat', function(ChatEvent $args, Client $bot) { ... });
```

The `EventEmitter::on()` method signature accepts `callable` — no change needed to the method itself. Consumers add type hints to closures for static analysis.

### Cmdr Router Integration
The cmdr router (`knivey/cmdr`) passes `$args` as-is to script handler functions. It needs no changes. Script handler signatures change from `object $args` to `ChatEvent $args`:
```php
// Before
function myCommand(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {

// After
function myCommand(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
```

## Files Changed

### New Files (in `library/Irc/Event/`)
- `Event.php` — abstract base
- `UserEvent.php` — abstract, user info fields
- `ChatEvent.php`, `PmEvent.php`, `NoticeEvent.php`
- `ModeEvent.php`, `ChannelModeIsEvent.php`
- `QuitEvent.php`
- `JoinEvent.php`, `PartEvent.php`, `KickEvent.php`
- `NickEvent.php`
- `NamesEvent.php`, `NamesReply.php`
- `ListEvent.php`, `ListEntry.php`
- `PongEvent.php`, `OptionsEvent.php`
- `NumericEvent.php`
- `MessageEvent.php`, `SentEvent.php` (internal plumbing events)
- `WelcomeEvent.php`, `DisconnectedEvent.php`

### Modified Library Files
- `library/Irc/EventEmitter.php` — `emit()` signature change
- `library/Irc/Client.php` — ~20 emit sites construct typed objects, NamesReply/ListEntry usage

### Modified Consumer Files (~30)
- `library/Nicks.php` — ~20 callback type hints + property accesses
- `library/Channels.php` — ~15 callback type hints + property accesses
- `lolbot.php` — ~5 callbacks + property accesses
- `artbots.php` — ~5 callbacks + property accesses
- `NetworkContext.php` — ~1 callback + property accesses
- `artbot_scripts/` — art-common, quotes, urlimg, drawing, bashorg, artfart, help
- `scripts/` — ~20 files with `$args` type hint and property cleanup

### PSR-4 Autoloading
Add to `composer.json` autoload section:
```
"Irc\\Event\\" → "library/Irc/Event/"
```

## Risks

### KickEvent $nick meaning change
Currently `$args->nick` is the kickee (arg 1). After the change, `$args->nick` is the kicker (from prefix) and `$args->target` is the kickee. All kick handlers (~5 sites) must be audited.

### Compound event dispatching
`emit()` splits compound names like `"chat, chat:$to"` and re-dispatches. The same Event object is reused. `$event` must be mutable to reflect the correct name per dispatch. Only `$event` is mutable — all other fields are readonly.

### Internal message/sent events
`Client.php` emits `message` and `sent` events internally (lines 457, 570). The `message` event passes `{message: Message, raw: string}` and the `sent` event passes `{message: Message}`. These become two additional concrete events:

```
Irc\Event\MessageEvent extends Event
    public readonly Message $message
    public readonly string $raw

Irc\Event\SentEvent extends Event
    public readonly Message $message
```

No scripts listen for them directly, so consumer changes are limited to `Client.php` itself.

### Variable naming
The `$args` variable name is preserved everywhere — only the type changes. This minimizes diff noise and review burden.
