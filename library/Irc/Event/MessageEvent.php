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
