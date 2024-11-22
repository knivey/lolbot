<?php

namespace scripts;

use knivey\cmdr\Cmdr;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use lolbot\entities\Server;

//TODO add entityManager here

class script_base
{
    public function __construct(
     public readonly Network $network,
     public readonly Bot $bot,
     public readonly Server $server,
     public readonly array $config,
     public readonly \Irc\Client $client,
     public readonly \Psr\Log\LoggerInterface $logger,
     public readonly \Nicks $nicks,
     public readonly \Channels $chans,
     public readonly Cmdr $router,
    )
    {
        $this->init();
    }

    public function init(): void {}
}