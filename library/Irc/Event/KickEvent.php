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
