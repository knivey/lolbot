<?php

namespace scripts;

use knivey\cmdr\Cmdr;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use lolbot\entities\Server;

//TODO add entityManager here

class script_base
{
    /**
     * @param array<string, mixed> $config
     */
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

    /**
     * Returns the bot's command trigger prefix as the user actually typed it
     * (e.g. "!" or ";"), for use in messages such as "try ;findcoin". Handles
     * both char triggers ($bot->trigger) and regex triggers ($bot->trigger_re);
     * for regex triggers the matched text is extracted from the message,
     * mirroring the dispatch logic in lolbot.php.
     */
    protected function triggerPrefix(\Irc\Event\ChatEvent $args): string
    {
        if ($this->bot->trigger !== null && $this->bot->trigger !== '') {
            return $this->bot->trigger;
        }
        if ($this->bot->trigger_re !== null && $this->bot->trigger_re !== '') {
            $trig = "/(^{$this->bot->trigger_re}).+$/";
            if (preg_match($trig, $args->text, $m)) {
                return $m[1] ?? '';
            }
        }
        return '';
    }
}