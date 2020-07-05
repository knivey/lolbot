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

require_once 'youtube.php';
require_once 'weather.php';
require_once 'bing.php';
require_once 'stocks.php';
require_once 'wolfram.php';

$config = Yaml::parseFile(__DIR__.'/config.yaml');

use Amp\Loop;

Loop::run( function() {
    global $config;
    $bot = new \Irc\Client($config['name'], $config['server'], $config['port'], $config['bindIp'], $config['ssl']);

    $bot->on('welcome', function ($e, \Irc\Client $bot) {
        global $config;
        $nick = $bot->getNick();
        $bot->send("MODE $nick +x");
        $bot->join(implode(',', $config['channels']));
    });

    $bot->on('chat', function ($args, $bot) {
        $chan = $args->channel;
        $a = explode(' ', $args->text);
        $a[0] = strtolower($a[0]);
        if ($a[0] == '.knio') {
            $bot->pm($chan, "Knio is a cool guy");
            return;
        }

        \Amp\asyncCall('youtube', $bot, $chan, $args->text);

        if ($a[0] == '.wz' || $a[0] == '.weather' || $a[0] == '.fc') {
            \Amp\asyncCall('weather', $a, $bot, $chan);
        }

        if ($a[0] == '.bing') {
            \Amp\asyncCall('bing', $a, $bot, $chan);
        }

        if ($a[0] == '.stock') {
            \Amp\asyncCall('bing', $a, $bot, $chan);
        }

        if ($a[0] == '.calc') {
            \Amp\asyncCall('calc', $a, $bot, $chan);
        }
    });
    Loop::onSignal(SIGINT, function () use ($bot) {
        echo "Caught SIGINT! exiting ...\n";
        yield from $bot->sendNow("quit :Caught SIGINT GOODBYE!!!!\r\n");
        //Loop::delay(2000, function() {exit;});
        exit;
    });

    while(1) {
        yield from $bot->go();
    }
});
