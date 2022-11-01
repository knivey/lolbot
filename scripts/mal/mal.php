<?php
namespace scripts\mal;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use simplehtmldom\HtmlDocument;

#[Cmd("mal", "myanimelist")]
#[Syntax("<search>...")]
#[CallWrap("Amp\asyncCall")]
function mal($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $url = "https://myanimelist.net/search/all?q=" .  urlencode($req->args["search"]);
    try {
        $body = yield async_get_contents($url);
    } catch (\async_get_exception $e) {
        $bot->pm($args->chan, "\2MAL:\2 {$e->getIRCMsg()}");
        return;
    }
    $doc = new HtmlDocument($body);
    $result = $doc->find('div.title', 0)?->find('a', 0)?->getAttribute('href');
    if(!$result) {
        $bot->pm($args->chan, "\2MAL:\2 no results found");
        return;
    }
    try {
        $body = yield async_get_contents($result);
    } catch (\async_get_exception $e) {
        $bot->pm($args->chan, "\2MAL:\2 {$e->getIRCMsg()}");
        return;
    }
    $p = new HtmlDocument($body);
    $name = $p->find('.title-name',0)?->text();
    $name_eng =$p->find('.title-english',0)?->text();
    $score = $p->find('.score',0)?->text();
    $score_users = $p->find('.score',0)?->getAttribute('data-user');
    $desc = wordwrap(str_replace("\r", "", $p->find('p[itemprop=description]',0)?->text()));

    $info = [];
    foreach($p->find('div.spaceit_pad') as $i) {
        $info[$i->find('span', 0)?->text()] = substr($i->text(), strlen($i->find('span', 0)?->text())+1);;
    }

    $out = "\2MAL:\2 $name ($name_eng) \2Rated\2 $score by $score_users \2Type:\2 {$info['Type:']} \2Status:\2 {$info['Status:']} \2Genre:\2 {$info['Genre:']} \2Duration:\2 {$info['Duration:']} \2Aired:\2 {$info['Aired:']}";
    if(isset($info['Episodes:']))
        $out .= " \2Episodes:\2 {$info['Episodes:']}";
    $bot->pm($args->chan, $out);
    foreach(explode("\n", $desc) as $line) {
        if(empty($line))
            continue;
        $bot->pm($args->chan, "  $line");
    }
    $bot->pm($args->chan, $result);
}