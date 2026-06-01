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
