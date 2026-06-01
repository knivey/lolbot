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
