<?php

namespace Irc\Event;

abstract class Event
{
    public readonly int $time;
    public string $event;
    public readonly \Irc\EventEmitter $sender;

    public function __construct(int $time, string $event, \Irc\EventEmitter $sender)
    {
        $this->time = $time;
        $this->event = $event;
        $this->sender = $sender;
    }
}
