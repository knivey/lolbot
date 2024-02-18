<?php
namespace scripts\mal;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;
use simplehtmldom\HtmlDocument;

#[Cmd("mal", "myanimelist")]
#[Syntax("<search>...")]
#[Desc("lookup a anime on myanimelist")]
#[CallWrap("Amp\asyncCall")]
function mal($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $url = "https://myanimelist.net/search/all?q=" .  urlencode($cmdArgs["search"] ."&cat=anime");
    try {
        $body = yield async_get_contents($url);
    } catch (\async_get_exception $e) {
        $bot->pm($args->chan, "\2MAL:\2 {$e->getIRCMsg()}");
        return;
    } catch (\Exception $e) {
        $bot->pm($args->chan, "MAL: {$e->getMessage()}");
    }
    $doc = new HtmlDocument($body);
    $result = $doc->find('div.title', 0)?->find('a', 0)?->getAttribute('href');
    foreach($doc->find('div.title') as $e) {
        if(strtolower($e->find('a', 0)?->text()) == strtolower($cmdArgs["search"]))
            $result = $e->find('a', 0)?->getAttribute('href');
    }

    if(!$result) {
        $bot->pm($args->chan, "\2MAL:\2 no results found");
        return;
    }
    try {
        $body = yield async_get_contents($result);
    } catch (\async_get_exception $e) {
        $bot->pm($args->chan, "\2MAL:\2 {$e->getIRCMsg()}");
        return;
    } catch (\Exception $e) {
        $bot->pm($args->chan, "MAL: {$e->getMessage()}");
    }
    $p = new HtmlDocument($body);
    $name = $p->find('.title-name',0)?->text();
    $name_eng =$p->find('.title-english',0)?->text();
    $score = $p->find('.score',0)?->text();
    $score_users = $p->find('.score',0)?->getAttribute('data-user');
    $desc = wordwrap(str_replace("\r", "", $p->find('p[itemprop=description]',0)?->text()));

    $info = [];
    $genres = "";
    $themes = "";
    $demographic = "";
    foreach($p->find('div.spaceit_pad') as $i) {
        if($i->find('span', 0)?->text() == "Genres:") {
            $gs = [];
            foreach($i->find('a') as $g) {
                $gs[] = $g->text();
            }
            $genres = "\2Genres:\2 " . implode(', ', $gs);
            continue;
        }
        if($i->find('span', 0)?->text() == "Themes:") {
            $ts = [];
            foreach($i->find('a') as $t) {
                $ts[] = $t->text();
            }
            $themes = "\2Themes:\2 " . implode(', ', $ts);
            continue;
        }
        if($i->find('span', 0)?->text() == "Demographic:") {
            $demographic = "\2Demographic:\2 " . $i->find('a', 0)->text();
            continue;
        }
        $info[$i->find('span', 0)?->text()] = substr($i->text(), strlen($i->find('span', 0)?->text())+1);;
    }
var_dump($info);

    if(isset($info["Genre:"]))
        $genres = "\2Genre:\2 {$info['Genre:']}";
    if(isset($info["Theme:"]))
        $genres = "\2Theme:\2 {$info['Theme:']}";

    $bot->pm($args->chan, "\2MAL:\2 $name ($name_eng) \2Score:\2 $score by $score_users");
    $out = "\2Type:\2 {$info['Type:']} ({$info['Status:']}) ";
    $out .= "\2Duration:\2 {$info['Duration:']} \2Aired:\2 {$info['Aired:']}";
    if(isset($info['Episodes:']) && $info['Episodes:'] > 1)
        $out .= " \2Episodes:\2 {$info['Episodes:']}";
    $bot->pm($args->chan, $out);
    $bot->pm($args->chan, "$genres $themes \2Rated:\2 {$info['Rating:']} $demographic");
    foreach(explode("\n", $desc) as $line) {
        if(empty($line))
            continue;
        $bot->pm($args->chan, "  $line");
    }
    $bot->pm($args->chan, $result);
}