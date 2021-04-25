<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;


global $router;
$router->add('dns', '\Amp\asyncCall', ['dns'],'<query>');
function dns($nick, $chan, \Irc\Client $bot, knivey\cmdr\Request $req)
{
    global $config;
    try {
        /** @var Amp\Dns\Record[] $records */
        $records = yield Amp\Dns\resolve($req->args['query']);
        $recs = [];
        foreach ($records as $r) {
            $recs[] = $r->getValue();
        }
        if(count($recs) == 0)
            $recs = 'No records';
        else
            $recs = implode(', ', $recs);
        $bot->pm($chan, "DNS for {$req->args['query']} - $recs");
    } catch (Exception $e) {
        $bot->pm($chan, "DNS Exception {$e->getMessage()}");
    }
}