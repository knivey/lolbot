<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__.'/library/helpers.php';
use Symfony\Component\Yaml\Yaml;

set_include_path(implode(PATH_SEPARATOR, array(__DIR__.'/library', __DIR__.'/plugins', get_include_path())));

spl_autoload_register( function($class)
{
    $path = str_replace('\\', '/', $class).'.php';
    include $path;
    return class_exists($class, false);
});

use Amp\Loop;

$router = new Clue\Commander\Router();

function handleCommand($text, $nick, $chan, $bot) {
    global $router;
    foreach ($router->getRoutes() as $route) {
        $input = explode(' ', $text);
        $output = array();
        if ($route->matches($input, $output)) {
            \Amp\asyncCall($route->handler, $output, $nick, $chan, $bot);
            return;
        }
    }
}

require_once 'scripts/youtube/youtube.php';
require_once 'scripts/weather/weather.php';
require_once 'scripts/bing/bing.php';
require_once 'scripts/stocks/stocks.php';
require_once 'scripts/wolfram/wolfram.php';
require_once 'scripts/notifier/notifier.php';
require_once 'scripts/lastfm/lastfm.php';
require_once 'scripts/info/info.php';
require_once 'scripts/cumfacts/cumfacts.php';
require_once 'scripts/artfart/artfart.php';

$config = Yaml::parseFile(__DIR__.'/config.yaml');

Loop::run( function() {
    global $config;

    $bot = new \Irc\Client($config['name'], $config['server'], $config['port'], $config['bindIp'], $config['ssl']);
    $bot->setThrottle($config['throttle'] ?? true);
    $bot->setServerPassword($config['pass'] ?? '');

    $bot->on('welcome', function ($e, \Irc\Client $bot) {
        global $config;
        $nick = $bot->getNick();
        $bot->send("MODE $nick +x");
        $bot->join(implode(',', $config['channels']));
    });

    $bot->on('chat', function ($args, \Irc\Client $bot) {
        global $config;
        if ($config['youtube']) {
            \Amp\asyncCall('youtube', $bot, $args->channel, $args->text);
        }

        if(substr($args->text, 0, 1) != $config['trigger']) {
            return;
        } else {
            $text = substr($args->text, 1);
        }

        handleCommand($text, $args->from, $args->channel, $bot);
    });
    $server = yield from notifier($bot);

    Loop::onSignal(SIGINT, function ($watcherId) use ($bot, $server) {
        echo "Caught SIGINT! exiting ...\n";
        yield from $bot->sendNow("quit :Caught SIGINT GOODBYE!!!!\r\n");
        $bot->exit();
        if($server != null) {
            $server->stop();
        }
        Amp\Loop::cancel($watcherId);
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
