<?php

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

use simplehtmldom\HtmlDocument;

//TODO --amt for ud

#[Cmd("ud", "urban", "urbandict")]
#[Syntax('<query>...')]
#[CallWrap("Amp\asyncCall")]
function ud($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    $query = urlencode($cmdArgs['query']);
    try {
        $body = yield async_get_contents("http://www.urbandictionary.com/define.php?term=$query");
    } catch (\async_get_exception $e) {
        // we get a 404 if word not found
        if($e->getCode() == 404) {
            $bot->msg($args->chan, "ud: There are no definitions for this word.");
        } else {
            echo $e->getCode() . ' ' . $e->getMessageStripped();
            $bot->msg($args->chan, "ud: Problem getting data from urbandictionary");
        }
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "urbandict error: {$error->getMessage()}");
        return;
    }
    if (str_contains($body, "<div class=\"term space\">Sorry, we couldn't find:")) {

        return;
    }
    $doc = new HtmlDocument($body);

    $defs = @$doc->find('div.definition');

    // wonder if this would happen after that earlier check?
    if (!$defs || empty($defs)) {
        $bot->msg($args->chan, "ud: Couldn't find an entry matching {$cmdArgs['query']}");
        return;
    }

    $max = 2;
    $num = 0;
    for ($i = 0; $i < $max && isset($defs[$i]); $i++) {
        if ((!isset($config['throttle']) || $config['throttle']) && $i > 1)
            break;
        $def = $defs[$i];
        //$num = $def->find('div.ribbon', 0)->plaintext;
        $num++;
        if(str_contains($num, "Word of the Day")) {
            $max++;
            continue;
        }
        $meaning = $def->find('div.meaning', 0)->plaintext;
        $example = $def->find('div.example', 0)->plaintext;
        $word = $def->find('a.word', 0)->plaintext;
        $by = $def->find('div.contributor', 0)->plaintext;

        $meaning = html_entity_decode($meaning, ENT_QUOTES | ENT_HTML5);
        $example = html_entity_decode($example, ENT_QUOTES | ENT_HTML5);
        $word = html_entity_decode($word, ENT_QUOTES | ENT_HTML5);
        $by = html_entity_decode($by, ENT_QUOTES | ENT_HTML5);

        $meaning = trim(str_replace(["\n", "\r"], ' ', $meaning));

        $example = str_replace("\r", "\n", $example);
        $example = explode("\n", $example);
        $example = array_map('trim', $example);
        $example = array_filter($example);
        $example1line = implode(' | ', $example);

        $bot->msg($args->chan, "ud: $word #$num added $by");
        if (!isset($config['throttle']) || $config['throttle']) {
            $bot->msg($args->chan, " ├ Meaning: $meaning");
            $bot->msg($args->chan, " └ $example1line");
        } else {
            $c = 0;
            foreach(explode("\n", wordwrap($meaning, 80)) as $m) {
                $leader = $c > 0 ? "│ " : "├ ";
                $bot->msg($args->chan, " $leader $m");
                $c++;
            }
            $c = 0;
            foreach($example as $e) {
                $leader = $c > 0 ? "│ " : "├ ";
                if($c == count($example)-1)
                    $leader = "└ ";
                $bot->msg($args->chan, " $leader   $e");
                $c++;
            }
        }
    }
}