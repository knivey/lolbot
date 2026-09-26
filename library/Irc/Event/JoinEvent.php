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
        ?string $account = null,
        public readonly ?string $realname = null,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost, $account);
    }
}
