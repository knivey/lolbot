<?php
require_once __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

set_include_path(implode(PATH_SEPARATOR, array(__DIR__.'/library', __DIR__.'/plugins', get_include_path())));

spl_autoload_register( function($class)
{
    $path = str_replace('\\', '/', $class).'.php';
    include $path;
    return class_exists($class, false);
});

use Amp\Loop;
use knivey\cmdr\Cmdr;

$router = new Cmdr();


$config = Yaml::parseFile(__DIR__.'/config.yaml');
if($config['codesand'] ?? false) {
    require_once 'scripts/codesand/common.php';
}

require_once 'scripts/notifier/notifier.php';

require_once 'scripts/weather/weather.php';
require_once 'scripts/bing/bing.php';
require_once 'scripts/stocks/stocks.php';
require_once 'scripts/wolfram/wolfram.php';
require_once 'scripts/lastfm/lastfm.php';
require_once 'scripts/help/help.php';
require_once 'scripts/cumfacts/cumfacts.php';
require_once 'scripts/artfart/artfart.php';
require_once 'scripts/tools/tools.php';

require_once 'scripts/linktitles/linktitles.php';
require_once 'scripts/youtube/youtube.php';

$router->loadFuncs();

$bot = null;
Loop::run( function() {
    global $bot, $config;

    $bot = new \Irc\Client($config['name'], $config['server'], $config['port'], $config['bindIp'], $config['ssl']);
    $bot->setThrottle($config['throttle'] ?? true);
    $bot->setServerPassword($config['pass'] ?? '');

    $bot->on('welcome', function ($e, \Irc\Client $bot) {
        global $config;
        $nick = $bot->getNick();
        $bot->send("MODE $nick +x");
        $bot->join(implode(',', $config['channels']));
    });

    $bot->on('kick', function($args, \Irc\Client $bot) {
        $bot->join($args->channel);
    });

    $bot->on('chat', function ($args, \Irc\Client $bot) {
        global $config, $router;
        if ($config['youtube'] ?? false) {
            \Amp\asyncCall('youtube', $bot, $args->from, $args->channel, $args->text);
        }
        if ($config['linktitles'] ?? false) {
            \Amp\asyncCall('linktitles', $bot, $args->channel, $args->text);
        }

        if(isset($config['trigger'])) {
            if(substr($args->text, 0, 1) != $config['trigger']) {
                return;
            }
            $text = substr($args->text, 1);
        } elseif(isset($config['trigger_re'])) {
            $trig = "/(^${config['trigger_re']}).+$/";
            if (!preg_match($trig, $args->text, $m)) {
                return;
            }
            $text = substr($args->text, strlen($m[1]));
        } else {
            echo "No trigger defined\n";
            return;
        }


        $ar = explode(' ', $text);
        if (array_shift($ar) == 'ping') {
            $bot->msg($args->channel, "Pong");
        }


        $text = explode(' ', $text);
        $cmd = array_shift($text);
        $text = implode(' ', $text);
        try {
            $router->call($cmd, $text, $args, $bot);
        } catch (Exception $e) {
            $bot->notice($args->from, $e->getMessage());
        }
    });
    $server = yield from notifier($bot);

    Loop::onSignal(SIGINT, function ($watcherId) use ($bot, $server) {
        Amp\Loop::cancel($watcherId);
        if(!$bot->isConnected)
            die("Terminating, not connected\n");
        echo "Caught SIGINT! exiting ...\n";
        yield from $bot->sendNow("quit :Caught SIGINT GOODBYE!!!!\r\n");
        $bot->exit();
        if($server != null) {
            $server->stop();
        }
    });

    while(!$bot->exit) {
        yield from $bot->go();
    }
    if($bot->exit) {
        echo "Stopping Amp\\Loop\n";
        Amp\Loop::stop();
        //exit();
        return;
    }
});

/*
 * will probably move this later to some kinda user auth thing
 */


function getUserAuthServ($nick, $bot): \Amp\Promise {
    return \Amp\call(function () use ($nick, $bot) {
        $idx = null;
        $auth = null;
        $success = false;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $nick, &$success, &$def) {
            if ($args->nick != 'AuthServ')
                return;
            if (preg_match("/Account information for \x02([^\x02]+)\x02:/", $args->text, $m)) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve($m[1]);
            }
            $rnick = preg_quote($nick, '/');
            if (preg_match("/User with nick \x02{$rnick}\x02 does not exist\./", $args->text) ||
                preg_match("/{$rnick} must first authenticate with \x02AuthServ\x02\./", $args->text)
            ) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve(null);
            }
        };
        $bot->on('notice', $cb, $idx);
        $bot->send("as info $nick");
        $auth = yield \Amp\Promise\timeout($def->promise(), 2000);
        if (!$success)
            $bot->off('notice', null, $idx);
        return $auth;
    });
}

function getUserChanAccess($nick, $chan, $bot): \Amp\Promise {
    return \Amp\call(function () use ($nick, $chan, $bot) {
        $idx = null;
        $auth = null;
        $success = false;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $nick, $chan, &$success, &$def) {
            if ($args->nick != 'ChanServ')
                return;
            $rnick = preg_quote($nick, '/');
            $rchan = preg_quote($chan, '/');
            if (preg_match("/{$rnick} [^ ]+ has access \x02([^\x02]+)\x02 in {$rchan}/", $args->text, $m)) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve($m[1]);
            }
            if (preg_match("/User with nick \x02{$rnick}\x02 does not exist\./", $args->text) ||
                preg_match("/{$rnick} must first authenticate with \x02AuthServ\x02\./", $args->text) ||
                preg_match("/{$rchan} has not been registered with ChanServ./", $args->text)
            ) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve(0);
            }
        };
        $bot->on('notice', $cb, $idx);
        $bot->send("cs $chan access $nick");
        $auth = yield \Amp\Promise\timeout($def->promise(), 2000);
        if (!$success)
            $bot->off('notice', null, $idx);
        return $auth;
    });
}

