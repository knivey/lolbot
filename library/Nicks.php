<?php
use function knivey\tools\get_akey_nc;

// Just grabbign this from an old bot project i made and making it work here for now

/**
 * Track IRC Nicks and Hosts
 */
class Nicks {
    /**
     * Stores temporary hosts for PMed commands from no shared chan
     * stored as $tppl['nick'] = 'host'
     * @var Array
     */
    public $tppl = Array();
    /**
     * Stores all our known nicks
     * @var Array
     */
    public $ppl = Array();
    static protected $pplTemplate;

    function __construct(\Irc\Client $bot) {
        self::$pplTemplate  = Array(
            'host' => NULL,
            'channels' => Array(),
//            'lastMsgTime' => NULL,
//            'lastMsg' => Array('target' => NULL, 'msg' => NULL)
        );
        $this->bot = $bot;
        $this->setupHooks($bot);
    }

    protected \Irc\Client $bot;

    protected $whoHook = [];

    public function setupHooks(\Irc\Client $bot) {
        $bot->on('welcome', function($args, $bot) {
            $this->clearAll();
        });
        $doWho = function($args, $bot) {
            if(!empty($this->whoHook))
                $bot->off($this->whoHook[0], null, $this->whoHook[1]);
            //If server doesn't have multi-prefix or WHOX then we likely will not be knowing full op/voice state
            //On some servers (ircu) whox will give all the @+
            $idx = null;
            if($bot->hasOption('WHOX')) {
                $this->whoHook[0] = '354';
                $bot->on('354', function($args, $bot) {$this->whox($args->message);}, $idx);
            } else {
                $this->whoHook[0] = '352';
                $bot->on('352', function($args, $bot) {$this->who($args->message);}, $idx);
            }
            $this->whoHook[1] = $idx;
        };
        $bot->on('422', $doWho);
        $bot->on('376', $doWho);
        $bot->on('names', function($args, $bot) {$this->names($args->channel, $args->names);});


        $bot->on('join', function($args, $bot) {
            if($bot->isCurrentNick($args->nick)) {
                if($bot->hasOption('WHOX')) {
                    $bot->send("WHO {$args->channel} %tnchuf,777");
                } else {
                    $bot->send("WHO {$args->channel}");
                }
            }

            $this->join($args->nick, $args->identhost, $args->channel);
        });
        $bot->on('part', function($args, $bot) {
            if($bot->isCurrentNick($args->nick))
                $this->usPart($args->channel);
            else
                $this->part($args->nick, $args->channel);
        });
        $bot->on('nick', function($args, $bot) {$this->nick($args->old, $args->new);});
        $bot->on('quit', function($args, $bot) {$this->quit($args->nick);});
        $bot->on('kick', function($args, $bot) {
            if($bot->isCurrentNick($args->nick))
                $this->usPart($args->channel);
            else
                $this->kick($args->nick, $args->channel);
        });
        $bot->on('mode', function($args, $bot) {$this->mode($args, $bot);});
        $bot->on('pm', function($args, $bot) {$this->tppl($args->nick, $args->identhost);});
        $bot->on('chat', function($args, $bot) {$this->tpplClear();});
    }

    function mode($args, \Irc\Client $bot) {
        if($args->on[0] != "#") {
            return;
        }
        $modeArgs = $args->args;
        $modeString = array_shift($modeArgs);
        $modeArgs = array_values($modeArgs);
        $adding = true;
        //should really use the isupport to determine these but afaik it's pretty universal
        //PREFIX=(qaohv)~&@%+ (owner, admin, op, half-op, voice)
        //have to get all modes that take args and filter out
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
            if(str_contains(($CHANMODES[0]??'').($CHANMODES[1]??''), $mode) ||
                (str_contains($CHANMODES[2]??'', $mode) && $adding)) {
                array_shift($modeArgs);
                continue;
            }
            switch ($mode) {
                case 'q':
                    if ($adding)
                        $this->Owner(array_shift($modeArgs), $args->target);
                    else
                        $this->DeOwner(array_shift($modeArgs), $args->target);
                    break;
                case 'a':
                    if ($adding)
                        $this->Admin(array_shift($modeArgs), $args->target);
                    else
                        $this->DeAdmin(array_shift($modeArgs), $args->target);
                    break;
                case 'o':
                    if ($adding)
                        $this->Op(array_shift($modeArgs), $args->target);
                    else
                        $this->DeOp(array_shift($modeArgs), $args->target);
                    break;
                case 'h':
                    if ($adding)
                        $this->HalfOp(array_shift($modeArgs), $args->target);
                    else
                        $this->DeHalfOp(array_shift($modeArgs), $args->target);
                    break;
                case 'v':
                    if ($adding)
                        $this->Voice(array_shift($modeArgs), $args->target);
                    else
                        $this->DeVoice(array_shift($modeArgs), $args->target);
            }
        }
    }

    /**
     * Clears all data
     */
    function clearAll() {
        $this->ppl = Array();
        $this->tppl = Array();
    }

    /**
     * Set the temporary ppl array
     * @param string $nick
     * @param string $host
     */
    function tppl($nick, $host) {
        $this->tpplClear();
        //If nick already exists then update the host and don't add to tppl
        $key = get_akey_nc($nick, $this->ppl);
        if($key) {
            $this->ppl[$key]['host'] = $host;
            return;
        }
        $this->tppl[$nick] = $host;
    }

    /**
     * Reset the temporary people array
     */
    function tpplClear() {
        $this->tppl = Array();
    }


    function names($chan, $names) {
        $names = $names->names;
        foreach ($names as $n) {
            $chanModes = array_intersect(['+','%','@','&','~'], str_split($n));
            $chanModes = array_flip($chanModes);
            foreach ($chanModes as $k => &$v) {
                $v = $k;
            }
            $n = ltrim($n, '~&@%+');
            $key = get_akey_nc($n, $this->ppl);
            if ($key == null) {
                $this->ppl[$n] = self::$pplTemplate;
                $this->ppl[$n]['channels'][$chan] = array('modes' => $chanModes, 'jointime' => null);
            } else {
                $this->ppl[$key]['channels'][$chan] = array('modes' => $chanModes, 'jointime' => null);
            }
        }
    }

    function who(\Irc\Message $msg) {
        $args = $msg->args;
        //              0        1         2          3      4        5      6       7
        //:server 352 <client> <channel> <username> <host> <server> <nick> <flags> :<hopcount> <realname>
        $key = get_akey_nc($args[5], $this->ppl);
        if ($key == null) {
            return; //Don't add the user we have no idea how they got here
        }
        $ckey = get_akey_nc($args[1], $this->ppl[$key]['channels']);
        if($ckey == null) {
            return; //Don't add information that we shouldn't be getting
        }
        $this->ppl[$key]['host'] = $args[2] . '@' . $args[3];
        //process the rest of their channel mode (@+)
        $chanModes = array_intersect(['+','%','@','&','~'], str_split($args[6]));
        $chanModes = array_flip($chanModes);
        foreach ($chanModes as $k => &$v) {
            $v = $k;
        }
        $this->ppl[$key]['channels'][$ckey]['modes'] = array_merge($this->ppl[$key]['channels'][$ckey]['modes'], $chanModes);
    }

    /**
     * Handle whox reply
     */
    function whox(\Irc\Message $msg) {
        $args = $msg->args;
        //            0       1         2       3     4    5    6
        //:server 354 ourname customnum channel ident host nick flags
        if ($args[1] != 777) {
            return; // wasn't our number
        }
        $key = get_akey_nc($args[5], $this->ppl);
        if ($key == null) {
            return; //Don't add the user we have no idea how they got here
        }
        $ckey = get_akey_nc($args[2], $this->ppl[$key]['channels']);
        if($ckey == null) {
            return; //Don't add information that we shouldn't be getting
        }
        $this->ppl[$key]['host'] = $args[3] . '@' . $args[4];
        //process the rest of their channel mode (@+)
        $chanModes = array_intersect(['+','%','@','&','~'], str_split($args[6]));
        $chanModes = array_flip($chanModes);
        foreach ($chanModes as $k => &$v) {
            $v = $k;
        }
        $this->ppl[$key]['channels'][$ckey]['modes'] = $chanModes;
    }

    /**
     * Handle join
     * @param string $nick
     * @param string $host
     * @param string $chan
     */
    function join($nick, $host, $chan) {
        $key = get_akey_nc($nick, $this->ppl);
        if ($key != null) {
            //good idea to make sure host is correct
            $this->ppl[$key]['host'] = $host;
            $this->ppl[$key]['channels'][$chan] = Array(
                'modes' => Array(),
                'jointime' => time()
            );
        } else {
            //Create a whole new ppl entry
            $this->ppl[$nick] = self::$pplTemplate;
            $this->ppl[$nick]['host'] = $host;
            $this->ppl[$nick]['channels'][$chan] = Array(
                'modes' => Array(),
                'jointime' => time(),
            );
        }
    }

    /**
     * Handle nick changes
     * @param string $oldnick
     * @param string $newnick
     */
    function nick($oldnick, $newnick) {
        $key = get_akey_nc($oldnick, $this->ppl);
        if($key == null) {
            return; //should never happen
        }
        $this->ppl[$newnick] = $this->ppl[$key];
        unset($this->ppl[$key]);
    }

    /**
     * Handle Parts
     * @param string $nick
     * @param string $chan
     */
    function part($nick, $chan) {
        $key = get_akey_nc($nick, $this->ppl);
        if ($key != null) {
            //check their channels if they just parted last one delete them otherwise update chans
            if (count($this->ppl[$key]['channels']) == 1) {
                unset($this->ppl[$key]);
            } else {
                $ckey = get_akey_nc($chan, $this->ppl[$key]['channels']);
                unset($this->ppl[$key]['channels'][$ckey]);
            }
        }
    }

    /**
     * Handle ourself leaving a channel
     * @param string $chan
     */
    function usPart($chan) {
        //see if its us leaving
        foreach ($this->ppl as $n => &$i) {
            $ckey = get_akey_nc($chan, $i['channels']);
            if ($ckey != null) {
                //See if that was the only channel we saw them on
                if (count($i['channels']) == 1) {
                    unset($this->ppl[$n]);
                } else {
                    unset($i['channels'][$ckey]);
                }
            }
        }
    }

    /**
     * Handle Kicks
     * @param string $nick
     * @param string $chan
     */
    function kick($nick, $chan) {
        $this->part($nick, $chan);
    }

    /**
     * Handle Quits
     * @param string $nick
     */
    function quit($nick) {
        $key = get_akey_nc($nick, $this->ppl);
        if($key != null) {
            unset($this->ppl[$key]);
        }
    }

    /**
     * Get the proper case for $nick and $chan keys in the ppl array
     * on failure empty array returned, otherwise Array(nick, chan)
     * @param string $nick
     * @param string $chan
     * @return Array
     */
    function getChanNickKey($nick, $chan) {
        $key = get_akey_nc($nick, $this->ppl);
        if($key == null) {
            return Array();
        }
        $ckey = get_akey_nc($chan, $this->ppl[$key]['channels']);
        if($ckey == null) {
            return Array();
        }
        return Array($key, $ckey);
    }

    function Owner($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        $this->ppl[$key]['channels'][$ckey]['modes']['~'] = '~';
    }

    function DeOwner($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        unset($this->ppl[$key]['channels'][$ckey]['modes']['~']);
    }

    function Admin($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        $this->ppl[$key]['channels'][$ckey]['modes']['&'] = '&';
    }

    function DeAdmin($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        unset($this->ppl[$key]['channels'][$ckey]['modes']['&']);
    }

    /**
     * Handle Op
     * @param string $nick
     * @param string $chan
     */
    function Op($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        $this->ppl[$key]['channels'][$ckey]['modes']['@'] = '@';
    }

    /**
     * Handle DeOp
     * @param string $nick
     * @param string $chan
     */
    function DeOp($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        unset($this->ppl[$key]['channels'][$ckey]['modes']['@']);
    }

    function HalfOp($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        $this->ppl[$key]['channels'][$ckey]['modes']['%'] = '%';
    }

    function DeHalfOp($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        unset($this->ppl[$key]['channels'][$ckey]['modes']['%']);
    }

    /**
     * Handle Voice
     * @param string $nick
     * @param string $chan
     */
    function Voice($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        $this->ppl[$key]['channels'][$ckey]['modes']['+'] = '+';
    }

    /**
     * Handle DeVoice
     * @param string $nick
     * @param string $chan
     */
    function DeVoice($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return;
        }
        list($key, $ckey) = $cnkeys;
        unset($this->ppl[$key]['channels'][$ckey]['modes']['+']);
    }

    function isOwner($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return false;
        }
        list($key, $ckey) = $cnkeys;
        return array_key_exists('~', $this->ppl[$key]['channels'][$ckey]['modes']);
    }

    function isAdmin($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return false;
        }
        list($key, $ckey) = $cnkeys;
        return array_key_exists('&', $this->ppl[$key]['channels'][$ckey]['modes']);
    }

    /**
     * Check if a user is opped on channel, If so return true
     * @param string $nick
     * @param string $chan
     * @return boolean
     */
    function isOp($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return false;
        }
        list($key, $ckey) = $cnkeys;
        return array_key_exists('@', $this->ppl[$key]['channels'][$ckey]['modes']);
    }

    function isHalfOp($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return false;
        }
        list($key, $ckey) = $cnkeys;
        return array_key_exists('%', $this->ppl[$key]['channels'][$ckey]['modes']);
    }

    /**
     * Check if a user is voiced on channel, If so return true
     * @param string $nick
     * @param string $chan
     * @return boolean
     */
    function isVoice($nick, $chan) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return false;
        }
        list($key, $ckey) = $cnkeys;
        return array_key_exists('+', $this->ppl[$key]['channels'][$ckey]['modes']);
    }

    function hasModes($nick, $chan, $modes) {
        $cnkeys = $this->getChanNickKey($nick, $chan);
        if(empty($cnkeys)) {
            return false;
        }
        list($key, $ckey) = $cnkeys;
        $keys = array_keys($this->ppl[$key]['channels'][$ckey]['modes']);
        if(!empty(array_intersect($modes, $keys))) {
            return true;
        }
        return false;
    }

    function isVoiceOrHigher($nick, $chan) {
        return $this->hasModes($nick, $chan, ['+','%','@','&','~']);
    }

    function isHalfOpOrHigher($nick, $chan) {
        return $this->hasModes($nick, $chan, ['%','@','&','~']);
    }

    function isOpOrHigher($nick, $chan) {
        return $this->hasModes($nick, $chan, ['@','&','~']);
    }

    function isAdminOrHigher($nick, $chan) {
        return $this->hasModes($nick, $chan, ['&','~']);
    }

    /**
     * Get the channels array for $nick or empty array on fail.
     * [$chan] = Array(
     *           'modes' => Array('+' => '+'),
     *           'jointime' => time(),
     *       );
     * @param string $nick
     * @return array
     */
    function nickChans($nick) {
        $key = get_akey_nc($nick, $this->ppl);
        if($key == null) {
            return Array();
        }
        return $this->ppl[$key]['channels'];
    }

    /**
     * Get the nicks belonging to host, empty array if none found
     * Array('nick1','nick2',...)
     * @param string $host
     * @return Array
     */
    function h2n($host) {
        $out = Array();
        $hostRE = \knivey\tools\globToRegex($host) . 'i';
        foreach($this->ppl as $n => $p) {
            if(preg_match($hostRE, $p['host'])) {
                $out[] = $n;
            }
        }
        foreach($this->tppl as $n => $p) {
            if(preg_match($hostRE, $p)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * Get the host for $nick, null on failure
     * @param string $nick
     * @return string|null
     */
    function n2h($nick) {
        $key = get_akey_nc($nick, $this->ppl);
        if($key == null) {
            $key = get_akey_nc($nick, $this->tppl);
            if($key != null) {
                return $this->tppl[$key];
            }
            return null;
        }
        return $this->ppl[$key]['host'];
    }
}


