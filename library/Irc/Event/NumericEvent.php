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
