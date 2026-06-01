<?php

namespace Irc\Event;

class NamesReply
{
    public string $nick = '';
    public string $channelType = '';
    public string $chan = '';
    public array $names = [];
}
