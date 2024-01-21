<?php

namespace scripts;

use lolbot\entities\Bot;
use lolbot\entities\Network;

class script_base
{
    public function __construct(
     public readonly Network $network,
     public readonly Bot $bot,
     public readonly array $config,
     public readonly \Irc\Client $client,
     public readonly \Psr\Log\LoggerInterface $logger,
    )
    {
        $this->init();
    }

    public function init(): void {}
}