<?php

namespace Irc\Event;

class ChannelModeIsEvent extends UserEvent
{
    /**
     * @param array<int|string, string> $args
     */
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
        public readonly array $args,
    ) {
        parent::__construct($time, $event, $sender, $nick, $ident, $host, $identhost, $fullhost);
    }
}
