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
