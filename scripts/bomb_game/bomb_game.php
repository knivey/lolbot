<?php
namespace scripts\bomb_game;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use RedBeanPHP\OODBBean;
use \RedBeanPHP\R as R;

// Clone of the sopel bomb game

/**
 * @var array $config
 */
class bomb_game
{
    const COLORS = ['Red', 'Yellow', 'Blue', 'White', 'Black'];
    const TIME = 2; //Minutes
    const ALREADY_BOMBING = [
        "I can't fit another bomb in %target%'s pants!"
    ];
    const BOMB = [
        "Hey, %target%! Don't look but, I think there's a bomb in your pants.\x02 %time% minute\x02 timer, \x025 wires\x02: \x034Red\x03, \x038Yellow\x03, \x032Blue\x03, \x030,15White\x03 and \x031,15Black\x03. Which wire should I cut? Don't worry, I know what I'm doing! (respond with .cutwire color)"
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
        "Oh, come on, %target%! You could've at least picked one! Now you're dead. Guts, all over the place. You see that? Guts, all over YourPants. You should have picked the %color% wire."
    ];

    protected string $db;
    protected array $bombs = [];

    function __construct()
    {
        $this->db = 'bomb-' . uniqid();
        $dbfile = (string)($config['bombdb'] ?? "bomb.db");
        R::addDatabase($this->db, "sqlite:{$dbfile}");
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
    #[Syntax("<target>")]
    function bomb(object $args, \Irc\Client $bot, \knivey\cmdr\Request $req): void
    {
        \Amp\asyncCall(function () use ($args, $bot, $req) {
            $target = $req->args["target"];
            if (array_key_exists(strtolower($target), $this->bombs)) {
                $bot->msg($args->chan, self::randReply(self::ALREADY_BOMBING, compact("target")));
                return;
            }
            $color = self::COLORS[array_rand(self::COLORS)];
            $time = self::TIME;
            $bot->msg($args->chan, self::randReply(self::BOMB, compact("target", "time")));
            $bot->notice($args->nick, self::randReply(self::BOMBING, compact("target", "color")));
            $def = new \Amp\Deferred();
            $this->bombs[strtolower($target)] = compact("target", "color", "def");
            $result = yield \Amp\Promise\timeoutWithDefault($def->promise(), self::TIME * 60 * 1000, 0);
            if($result == 0) {
                //times up
                $bot->msg($args->chan, self::randReply(self::TIMESUP, compact("target", "color")));
            }
            unset($this->bombs[strtolower($target)]);
        });
    }

    #[Cmd("cutwire")]
    #[Syntax("<color>")]
    function cutwire(object $args, \Irc\Client $bot, \knivey\cmdr\Request $req): void
    {
        if (!array_key_exists(strtolower($args->nick), $this->bombs)) {
            return;
        }
        $target = $this->bombs[strtolower($args->nick)]['target'];
        $color = $this->bombs[strtolower($target)]['color'];
        if(strtolower($req->args['color']) == strtolower($color)) {
            $bot->msg($args->chan, self::randReply(self::DEFUSED, compact("target")));
            $this->bombs[strtolower($target)]['def']->resolve(1);
            return;
        }
        $bot->msg($args->chan, self::randReply(self::WRONG, compact("target", "color")));
        $this->bombs[strtolower($target)]['def']->resolve(2);
    }
}
