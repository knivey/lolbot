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
