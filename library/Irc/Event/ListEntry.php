<?php

namespace Irc\Event;

class ListEntry
{
    public function __construct(
        public readonly string $chan,
        public readonly string $userCount,
        public readonly string $topic,
    ) {}
}
