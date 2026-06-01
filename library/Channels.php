<?php

use Irc\Event\{JoinEvent, PartEvent, KickEvent, QuitEvent, ModeEvent, ChannelModeIsEvent, NumericEvent, NickEvent, NamesEvent};

/**
 * keep track of channel info, like modes, topics etc
 */

class Channel
{
    /**
     * indexed by mode and has value if mode has arg (like keys, limit etc)
     * @var array<string,string>
     */
    public array $modes = [];
    /**
     * @var array<string> simple array of nicks, if modes are needed use Nicks class
     */
    public array $nicks = [];
    public string $topic = "";
    public string $topicTime = "";
    public string $topicWho = "";
    //public array $bans;
}
class Channels
{
    /**
     * @var array<Channel>
     */
    private array $channels = [];
    public function __construct(\Irc\Client $bot)
    {
        //setup hooks
        $bot->on('welcome', function($args, $bot) {
            $this->channels = [];
        });
        $bot->on('join', function(JoinEvent $args, \Irc\Client $bot) {
            if(!isset($this->channels[strtolower($args->chan)]))
                $this->channels[strtolower($args->chan)] = new Channel();
            $this->channels[strtolower($args->chan)]->nicks[strtolower($args->nick)] = $args->nick;
            $bot->send("MODE", $args->chan);
        });
        $bot->on('names', function(NamesEvent $args, $bot) {
            if(!isset($this->channels[strtolower($args->chan)]))
                return;
            foreach($args->names->names as $n) {
                $n = ltrim($n, '~&@%+');
                $this->channels[strtolower($args->chan)]->nicks[strtolower($n)] = $n;
            }
        });
        $bot->on('part', function(PartEvent $args, $bot) {
            if(!isset($this->channels[strtolower($args->chan)]))
                return;
            if($bot->isCurrentNick($args->nick))
                unset($this->channels[strtolower($args->chan)]);
            else
                unset($this->channels[strtolower($args->chan)]->nicks[strtolower($args->nick)]);
        });
        $bot->on('quit', function(QuitEvent $args, $bot) {
            if($bot->isCurrentNick($args->nick))
                $this->channels = [];
            else {
                foreach($this->channels as &$channel) {
                    unset($channel->nicks[strtolower($args->nick)]);
                }
            }
        });
        $bot->on('kick', function(KickEvent $args, $bot) {
            if(!isset($this->channels[strtolower($args->chan)]))
                return;
            if($bot->isCurrentNick($args->target))
                unset($this->channels[strtolower($args->chan)]);
            else
                unset($this->channels[strtolower($args->chan)]->nicks[strtolower($args->target)]);
        });
        $bot->on('mode', function(ModeEvent $args, $bot) {
            $this->processModes($args->target, $args->args, $bot);
        });
        //RPL_CHANNELMODES
        $bot->on('324', function(ChannelModeIsEvent $args, $bot) {
            // shouldn't need to clear modes here
            $this->processModes($args->chan, $args->args, $bot);
        });
        //RPL_TOPIC
        $bot->on('332', function(NumericEvent $args, $bot) {
            $args = $args->message->args;
            if(!isset($this->channels[strtolower($args[1])]))
                return;
            $this->channels[strtolower($args[1])]->topic = $args[2];
        });
        //RPL_TOPICWHOTIME
        $bot->on('333', function(NumericEvent $args, $bot) {
            $args = $args->message->args;
            if(!isset($this->channels[strtolower($args[1])]))
                return;
            $this->channels[strtolower($args[1])]->topicWho = $args[2];
            $this->channels[strtolower($args[1])]->topicTime = $args[3];
        });
        $bot->on('nick', function(NickEvent $args, $bot) {
            foreach($this->channels as &$channel) {
                if(isset($channel->nicks[strtolower($args->old)])) {
                    $channel->nicks[strtolower($args->new)] = $channel->nicks[strtolower($args->old)];
                    unset($channel->nicks[strtolower($args->old)]);
                }
            }
        });
    }

    /**
     * @param array<string> $modeArgs
     */
    private function processModes(string $channel, array $modeArgs, \Irc\Client $bot): void {
        if($channel[0] != "#")
            return;
        if(!isset($this->channels[strtolower($channel)]))
            return;

        $modeString = array_shift($modeArgs);
        $modeArgs = array_values($modeArgs);
        $adding = true;
        $CHANMODES = $bot->getOption('CHANMODES', []);
        foreach (str_split($modeString) as $mode) {
            //If a switch is inside a loop, continue 2 will continue with the next iteration of the outer loop.
            switch ($mode) {
                case '+':
                    $adding = true;
                    continue 2;
                case '-':
                    $adding = false;
                    continue 2;
            }
            // https://modern.ircdocs.horse/#chanmodes-parameter
            // https://modern.ircdocs.horse/#mode-message

            //address to list modes (always has arg)
            if (str_contains($CHANMODES[0] ?? '', $mode)) {
                $arg = array_shift($modeArgs);
            }
            //change setting, must always have arg
            if (str_contains($CHANMODES[1] ?? '', $mode)) {
                $arg = array_shift($modeArgs);
                if($adding)
                    $this->channels[strtolower($channel)]->modes[$mode] = $arg;
                else
                    unset($this->channels[strtolower($channel)]->modes[$mode]);
            }
            //change setting, has arg when set, no args when unset
            if (str_contains($CHANMODES[2] ?? '', $mode)) {
                if($adding)
                    $this->channels[strtolower($channel)]->modes[$mode] = array_shift($modeArgs);
                else
                    unset($this->channels[strtolower($channel)]->modes[$mode]);
            }
            //change a setting, has no args
            if (str_contains($CHANMODES[3] ?? '', $mode)) {
                if($adding)
                    $this->channels[strtolower($channel)]->modes[$mode] = true;
                else
                    unset($this->channels[strtolower($channel)]->modes[$mode]);
            }
        }
    }

    public function hasMode(string $channel, string $mode) : bool {
        if(!isset($this->channels[strtolower($channel)]))
            return false;
        return isset($this->channels[strtolower($channel)]->modes[$mode]);
    }

    public function getChan(string $channel): Channel|false {
        return isset($this->channels[strtolower($channel)]) ?
            clone $this->channels[strtolower($channel)] : false;
    }

    /**
     * @return array<string>
     */
    public function dump(): array {
        return explode("\n", json_encode($this->channels, JSON_PRETTY_PRINT));
    }
}