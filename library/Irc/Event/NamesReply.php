<?php

namespace Irc\Event;

class NamesReply
{
    public string $nick = '';
    public string $channelType = '';
    public string $chan = '';
    /** @var array<int, string> */
    public array $names = [];
}
