<?php
namespace scripts\bomb_game;

use Amp\Deferred;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use RedBeanPHP\OODBBean;
use \RedBeanPHP\R as R;

// Clone of the sopel bomb game

/**
 * @var array $config
 */

/**
 * @var \Nicks $nicks
 */

class bomb {
    public function __construct(
        public string $target,
        public string $color,
        public Deferred $def
    )
    {
    }
}

class bomb_game
{
    const COLORS = [
        "\x034Red\x03" => "Red",
        "\x038Yellow\x03" => "Yellow",
        "\x0312Blue\x03" => "Blue",
        "\x0309Green\x03" => "Green",
        "\x0313Purple\x03" => "Purple"
    ];
    const TIME = 2; //Minutes
    const ALREADY_BOMBING = [
        "I can't fit another bomb in %target%'s pants!"
    ];

    const BOMB = [
        "Hey, %target%! Don't look but, I think there's a bomb in your pants.\x02 %time% minute\x02 timer, \x025 wires\x02: %colors%. Which wire should I cut? Don't worry, I know what I'm doing! (respond with .cutwire color)",
        "Hey, %target%! it's me again! Don't worry, but I think you're sitting on top of a ticking time bomb. \x02%time% minute\x02 timer, 5 wires: %colors%. Which wire should I snip? I can handle the pressure! (respond with .cutwire color)",
        "Hi %target%! Don't freak out, but I think there's a detonating device attached to your chair. %time% minute timer, 5 wires: %colors%. Which wire should I break? I'm sure I know what I'm doing! (respond with .cutwire color) ",
        "Hey %target%, once more! Don't be alarmed, but I think there's a dangerous explosive wrapped around your waist. %time% minute timer, 5 wires: %colors%. Which wire should I sever? I'm confident I can do this! (respond with .cutwire color)"
    ];
    const BOMBING = [
        "Hey, don't tell %target%, but the %color% wire? Yeah, that's the one. But shh! Don't say anything!"
    ];
    const DEFUSED = [
        "You did it, %target%! I'll be honest, I thought you were dead. But nope, you did it. You picked the right one. Well done."
    ];
    const WRONG = [
        "No! No, that's the wrong one. Aww, you've gone and killed yourself. Oh, that's... that's not good. No good at all, really. You should have picked the %color% wire."
    ];
    const TIMESUP = [
        "Oh, come on, %target%! You could've at least picked one! Now you're dead. Guts, all over the place. You see that? Guts, all over YourPants. You should have picked the %color% wire.",
        "Game over, %target%! You waited too long so the bomb blew up in your face. Now you're nothing more than a pile of rubble and smoke!",
        "Out of time, %target%! You failed the challenge and now you are paying the price. Welcome to the world of oblivion!",
        "You had a chance, %target%, but you blew it. The bomb just exploded and now you're history. Too bad..",
        "You failed, %target%, the bomb won't wait forever! Now you are nothing more than a pile of ash and debris.",
        "Oh no, %target%, you ran out of time! The bomb has exploded and now you're gone.",
        "Goodbye %target%, you should have acted faster. The bomb detonated and now your head is a mess.",
        "Time's up, %target%. You had your chance, but now you're dead. No more second chances, this time the bomb got you.",
        "Boom, %target%! You waited too long. The bomb exploded and now your guts are all over the place.",
        "Sorry %target%, but time ran out. The bomb detonated and you're gone..",
        "Too bad, %target%, you waited too long! Now your body is scattered in pieces and the bomb got you!"
    ];
    const NOT_ON_CHAN = [
        "I don't know where, %target% is!"
    ];

    protected string $db;
    protected array $bombs = [];

    function __construct()
    {
        $this->db = 'bomb-' . uniqid();
        $dbfile = (string)($config['bombdb'] ?? "bomb.db");
        R::addDatabase($this->db, "sqlite:{$dbfile}");
    }

    //TODO use db for bomb stats

    public function initIrcHooks(\Irc\Client $bot) {
        $bot->on('nick', function ($args, \Irc\Client $bot) {
            if(array_key_exists(strtolower($args->old), $this->bombs)) {
                $this->bombs[strtolower($args->new)] =& $this->bombs[strtolower($args->old)];
                $this->bombs[strtolower($args->new)]->target = $args->new;
                unset($this->bombs[strtolower($args->old)]);
            }
        });
    }

    static function randReply(array $templates, array $values) : string {
        $t = $templates[array_rand($templates)];
        $newVals = [];
        foreach($values as $k => $v) {
            $newVals['%'. $k .'%'] = $v;
        }
        return strtr($t, $newVals);
    }

    #[Cmd("bomb")]
    #[Desc("plant a bomb on target")]
    #[Syntax("<target>")]
    function bomb(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $nicks;
        $target = $cmdArgs["target"];
        if(empty($nicks->getChanNickKey($target, $args->chan))) {
            $bot->msg($args->chan, self::randReply(self::NOT_ON_CHAN, compact("target")));
            return;
        }
        \Amp\asyncCall(function () use ($args, $bot, $cmdArgs, $target) {
            if (array_key_exists(strtolower($target), $this->bombs)) {
                $bot->msg($args->chan, self::randReply(self::ALREADY_BOMBING, compact("target")));
                return;
            }
            $color = self::COLORS[array_rand(self::COLORS)];
            $colors =  implode(", ", array_keys(self::COLORS));
            $time = self::TIME;
            $bot->msg($args->chan, self::randReply(self::BOMB, compact("target", "time", "colors")));
            $bot->notice($args->nick, self::randReply(self::BOMBING, compact("target", "color")));
            $def = new \Amp\Deferred();
            $this->bombs[strtolower($target)] = new bomb($target, $color, $def);
            $bomb =& $this->bombs[strtolower($target)];
            $result = yield \Amp\Promise\timeoutWithDefault($def->promise(), self::TIME * 60 * 1000, 0);
            if($result == 0) {
                //times up
                //data could have changed during wait
                $target = $bomb->target;
                $color = $bomb->color;
                $bot->msg($args->chan, self::randReply(self::TIMESUP, compact("target", "color")));
            }
            unset($this->bombs[strtolower($bomb->target)]);
        });
    }

    #[Cmd("cutwire")]
    #[Desc("try to defuse your bomb")]
    #[Syntax("<color>")]
    function cutwire(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if (!array_key_exists(strtolower($args->nick), $this->bombs)) {
            return;
        }
        $target = $this->bombs[strtolower($args->nick)]->target;
        $color = $this->bombs[strtolower($target)]->color;
        if(strtolower($cmdArgs['color']) == strtolower($color)) {
            $bot->msg($args->chan, self::randReply(self::DEFUSED, compact("target")));
            $this->bombs[strtolower($target)]->def->resolve(1);
            return;
        }
        $bot->msg($args->chan, self::randReply(self::WRONG, compact("target", "color")));
        $this->bombs[strtolower($target)]->def->resolve(2);
    }
}
