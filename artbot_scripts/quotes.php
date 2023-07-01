<?php
use Amp\Loop;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use knivey\irctools;

use \RedBeanPHP\R as R;
global $config;
R::addDatabase('quotes', "sqlite:{$config['quotedb']}");

$quote_recordings = [];


#[Cmd("addquote", "quoteadd")]
#[Desc("add a quote, used this then paste the quotes to the chat and type @endquote, OR if its one line you can @addquote quoteline")]
#[Syntax("[quote]...")]
#[Option('--keeptimes', "We try to strip timestamps by default, use this to keep them")]
function addquote($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    $nick = $args->nick;
    $chan = $args->chan;
    global $quote_recordings, $config;
    if(isset($quote_recordings[$nick])) {
        return;
    }

    $quote_recordings[$nick] = [
        'nick' => $nick,
        'chan' => $chan,
        'lines' => [],
        'keeptimes' => $cmdArgs->optEnabled('--keeptimes'),
        'timeOut' => Amp\Loop::delay(15000, quoteTimeOut(...), [$nick, $bot]),
    ];
    if(isset($cmdArgs['quote'])) {
        $quote_recordings[$nick]['lines'][] = $cmdArgs['quote'];
        $quote_recordings[$nick]['lines'][] = "removed by array_pop";
        endquote($args, $bot, $cmdArgs);
        return;
    }
    $bot->pm($chan, "Quote recording started type \x02\x034@endquote\x03\x02 when done or discard with @cancelquote or just wait 15 seconds.");
}

function quoteTimeOut($watcher, $data): void {
    global $quote_recordings;
    list ($nick, $bot) = $data;
    if(!isset($quote_recordings[$nick])) {
        echo "Timeout called but not recording?\n";
        return;
    }
    $bot->pm($quote_recordings[$nick]['chan'], "Canceling quote recording for $nick due to no messages for 15 seconds");
    Amp\Loop::cancel($quote_recordings[$nick]['timeOut']);
    unset($quote_recordings[$nick]);
}

#[Cmd("endquote", "stopquote")]
#[Desc("Finish quote recording")]
function endquote($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    $nick = $args->nick;
    $host = $args->host;
    $chan = $args->chan;
    global $quote_recordings, $config;
    if(!isset($quote_recordings[$nick])) {
        $bot->pm($chan, "You aren't doing a recording");
        return;
    }
    Amp\Loop::cancel($quote_recordings[$nick]['timeOut']);
    //last line will be the command for end, so delete it
    array_pop($quote_recordings[$nick]['lines']);
    if(empty($quote_recordings[$nick]['lines'])) {
        $bot->pm($quote_recordings[$nick]['chan'], "Nothing recorded, cancelling");
        unset($quote_recordings[$nick]);
        return;
    }
    R::selectDatabase('quotes');
    $quote = R::dispense('quote');
    $quote->data = implode("\n", $quote_recordings[$nick]['lines']);
    $creator = R::findOne('creator', ' nick = ? AND host = ? ', [$nick, $host]);
    if($creator == null) {
        $creator = R::dispense('creator');
        $creator->nick = $nick;
        $creator->host = $host;
        R::store($creator);
    }
    $quote->creator = $creator;
    $quote->chan = $chan;
    $quote->date = R::isoDateTime();

    $id = R::store($quote);
    $bot->pm($quote_recordings[$nick]['chan'], "Quote recording finished ;) saved to id: $id");
    unset($quote_recordings[$nick]);
}

function stripTimestamp($line) {
    //var_dump($line);
    if(!preg_match("@^( *\[? *[\d:\-\\\/ ]+ *(?:am|pm)? *[\d:\-\\\/ ]* *]? *).+$@i", $line, $m)) {
        return $line;
    }
    $test = str_replace(['[',']'], '', $m[1]);
    //var_dump($test);
    if(!strtotime(trim($test))) {
        return $line;
    }
    return substr($line, strlen($m[1]));
}

#[Cmd("cancelquote")]
#[Desc("cancel and discard a quote recording")]
function cancelquote($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    $nick = $args->nick;
    $chan = $args->chan;
    global $quote_recordings;
    if(!isset($quote_recordings[$nick])) {
        $bot->pm($chan, "You aren't doing a quote recording");
        return;
    }
    $bot->pm($chan, "Quote recording canceled");
    Amp\Loop::cancel($quote_recordings[$nick]['timeOut']);
    unset($quote_recordings[$nick]);
}

#[Cmd("querch", "findquote")]
#[Syntax("<query>...")]
#[Desc("Search for any quotes that match using * for wildcard")]
#[Option("--play", "Play the results up to limit")]
function searchquote($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    R::selectDatabase('quotes');
    $query = "%" . str_replace("*", "%", $cmdArgs['query']) . "%";
    $quotes = R::find('quote', 'data LIKE ?', [$query]);
    if(empty($quotes)) {
        $bot->pm($args->chan, "Nothing found");
        return;
    }
    if($cmdArgs->optEnabled("--play")) {
        $cnt = 0;
        foreach($quotes as $quote) {
            $cnt++;
            if($cnt > 10) {
                pumpToChan($args->chan, ["Limiting to 10 quotes..."]);
                return;
            }
            showQuote($bot, $args->chan, $quote);
        }
        return;
    }
    $cnt = count($quotes);
    if($cnt == 1) {
        showQuote($bot, $args->chan, array_pop($quotes));
        return;
    }
    $ids = array_map(fn($it) => $it->id, $quotes);
    $ids = array_slice($ids, 0, 50);
    $ids = implode(', ', $ids);
    if($cnt > 50)
        $bot->pm($args->chan, "Found ($cnt) quotes limiting to 50: $ids");
    else
        $bot->pm($args->chan, "Found ($cnt) quotes: $ids");
}

function initQuotes($bot) {
    $bot->on('chat', function ($args, \Irc\Client $bot) {
        global $quote_recordings;
        $nick = $args->from;
        $text = $args->text;
        if(!isset($quote_recordings[$nick]))
            return;
        Amp\Loop::cancel($quote_recordings[$nick]['timeOut']);
        $quote_recordings[$nick]['timeOut'] = Amp\Loop::delay(15000, quoteTimeOut(...), [$nick, $bot]);
        if(!$quote_recordings[$nick]['keeptimes'])
            $text = stripTimestamp($text);
        $quote_recordings[$nick]['lines'][] = $text;
    });
}

#[Cmd("quote")]
#[Desc("Play a random quote or quote by id")]
#[Syntax("[id]")]
function cmd_quote($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    R::selectDatabase('quotes');
    if(isset($cmdArgs['id'])) {
        $quote = R::findOne('quote', ' id = ? ', [$cmdArgs['id']]);
        if($quote == null) {
            $bot->pm($args->chan, "Quote by that ID not found.");
            return;
        }
    } else {
        $quote = R::findFromSQL("quote", "SELECT * FROM quote ORDER BY RANDOM() LIMIT 1");
        $quote = array_pop($quote);
    }
    showQuote($bot, $args->chan, $quote);
}

function showQuote($bot, $chan, $quote) {
    $creator = $quote->creator;
    $header = "\2Quote {$quote['id']} recorded by {$creator['nick']} ({$creator['host']}) on {$quote['date']} in {$quote['chan']}";
    if(!is_string($quote['data'])) {
        $bot->pm($chan, "whoops something wrong with quote..");
        return;
    }
    $lines = explode("\n", $quote['data']);
    $lines = array_map(fn ($it) => "  $it", $lines);
    array_unshift($lines, $header);
    pumpToChan($chan, $lines);
}

/*
// need auth system so only TRUSTED users can delete
#[Cmd("delquote")]
function delquote($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {

}

//website not ready
#[Cmd("quoteweb")]
function quoteweb($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {

}
*/