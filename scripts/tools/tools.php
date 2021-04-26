<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

function getDnsType($type) {
    $rc = new ReflectionClass(Amp\Dns\Record::class);
    $ret = false;
    foreach(array_keys($rc->getConstants()) as $t) {
        if(strtolower($t) == strtolower($type))
            $ret = $t;
    }
    if($ret === false) {
        return false;
    }
    return $rc->getConstant($ret);
}

global $router;
$router->add('dns', '\Amp\asyncCall', ['dns'],'<query> [type]');
function dns($nick, $chan, \Irc\Client $bot, knivey\cmdr\Request $req)
{
    global $config;
    try {
        if(isset($req->args['type'])) {
            $type = getDnsType($req->args['type']);
            if($type === false) {
                $bot->pm($chan, "Unsupported record type");
                return;
            }

            /** @var Amp\Dns\Record[] $records */
            $records = yield Amp\Dns\query($req->args['query'], $type);
        } else {
            /** @var Amp\Dns\Record[] $records */
            $records = yield Amp\Dns\resolve($req->args['query']);
        }
        $recs = [];
        foreach ($records as $r) {
            $recs[] = $r->getValue();
        }
        if(count($recs) == 0)
            $recs = 'No records';
        else
            $recs = implode(' | ', $recs);
        $bot->pm($chan, "DNS for {$req->args['query']} ".($req->args['type'] ?? 'A, AAAA')." - $recs");
    } catch (Exception $e) {
        $bot->pm($chan, "DNS Exception {$e->getMessage()}");
    }
}