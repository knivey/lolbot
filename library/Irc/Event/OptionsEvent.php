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
