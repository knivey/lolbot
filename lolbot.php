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
            $router->call($cmd, $text, $args->from, $args->channel, $bot);
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
