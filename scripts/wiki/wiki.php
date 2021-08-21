<?php


use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

use simplehtmldom\HtmlDocument;

#[Cmd("wiki")]
#[Syntax('<query>...')]
#[CallWrap("Amp\asyncCall")]
function wiki($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    list($rpl, $rpln) = makeRepliers($args, $bot, "Wiki");
    $query = rawurlencode($req->args['query']);
    try {
        $body = yield async_get_contents("https://en.wikipedia.org/api/rest_v1/page/summary/$query");
    } catch (async_get_exception $e) {
        if($e->getCode() == 404) {
            $rpl("Wikipedia does not have an article with this exact name.", "404");
            return;
        }
        $rpl($e->getIRCMsg(), "error");
        return;
    } catch (\Exception $e) {
        $rpl($e->getMessage(), "error");
        return;
    }
    $json = json_decode($body);
    if($json == null) {
        $rpl("bad response from server", "error");
        return;
    }
    $title = null;
    $extract = null;
    $url = null;
    if(is_string($json->title))
        $title = html_entity_decode($json->title, ENT_QUOTES | ENT_HTML5);
    if(is_string($json->extract))
        $extract = html_entity_decode($json->extract, ENT_QUOTES | ENT_HTML5);
    if(is_string($json->content_urls?->desktop?->page))
        $url = html_entity_decode($json->content_urls->desktop->page, ENT_QUOTES | ENT_HTML5);

    if(strlen($extract) > 320) {
        $extract = explode("\n", wordwrap($extract, 320), 0)[0] . "...";
    }

    $type = null;
    if($json->type != "standard")
        $type = $json->type;

    $rpl("$title - $extract - $url", $type);
}