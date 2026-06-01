<?php

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;
use knivey\cmdr\Validate;

use \RedBeanPHP\R as R;


#[Cmd("addquote", "quoteadd")]
#[Desc("add a quote, used this then paste the quotes to the chat and type @endquote, OR if its one line you can @addquote quoteline")]
#[Syntax("[quote]...")]
#[Option('--keeptimes', "We try to strip timestamps by default, use this to keep them")]
function addquote(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
    $ctx = \NetworkContext::get($bot);
    $nick = $args->nick;
    $chan = $args->chan;
    if(isset($ctx->quoteRecordings[$nick])) {
        return;
    }

    $ctx->quoteRecordings[$nick] = [
        'nick' => $nick,
        'chan' => $chan,
        'lines' => [],
        'keeptimes' => $cmdArgs->optEnabled('--keeptimes'),
        'timeOut' => \Revolt\EventLoop::delay(15, fn() => quoteTimeOut($nick, $bot)),
    ];
    if(isset($cmdArgs['quote'])) {
        $ctx->quoteRecordings[$nick]['lines'][] = $cmdArgs['quote'];
        $ctx->quoteRecordings[$nick]['lines'][] = "removed by array_pop";
        endquote($args, $bot, $cmdArgs);
        return;
    }
    $bot->pm($chan, "Quote recording started type \x02\x034@endquote\x03\x02 when done or discard with @cancelquote or just wait 15 seconds.");
}

function quoteTimeOut(string $nick, \Irc\Client $bot): void {
    $ctx = \NetworkContext::get($bot);
    if(!isset($ctx->quoteRecordings[$nick])) {
        echo "Timeout called but not recording?\n";
        return;
    }
    $bot->pm($ctx->quoteRecordings[$nick]['chan'], "Canceling quote recording for $nick due to no messages for 15 seconds");
    \Revolt\EventLoop::cancel($ctx->quoteRecordings[$nick]['timeOut']);
    unset($ctx->quoteRecordings[$nick]);
}

#[Cmd("endquote", "stopquote")]
#[Desc("Finish quote recording")]
function endquote(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
    $ctx = \NetworkContext::get($bot);
    $nick = $args->nick;
    $host = $args->host;
    $chan = $args->chan;
    if(!isset($ctx->quoteRecordings[$nick])) {
        $bot->pm($chan, "You aren't doing a recording");
        return;
    }
    \Revolt\EventLoop::cancel($ctx->quoteRecordings[$nick]['timeOut']);
    //last line will be the command for end, so delete it
    array_pop($ctx->quoteRecordings[$nick]['lines']);
    if(empty($ctx->quoteRecordings[$nick]['lines'])) {
        $bot->pm($ctx->quoteRecordings[$nick]['chan'], "Nothing recorded, cancelling");
        unset($ctx->quoteRecordings[$nick]);
        return;
    }
    $ctx->initQuotesDb();
    R::selectDatabase("quotes_{$ctx->name}");
    $quote = R::dispense('quote');
    $quote->data = implode("\n", $ctx->quoteRecordings[$nick]['lines']);
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
    $bot->pm($ctx->quoteRecordings[$nick]['chan'], "Quote recording finished ;) saved to id: $id");
    unset($ctx->quoteRecordings[$nick]);
}

function stripTimestamp(string $line): string {
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
function cancelquote(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
    $ctx = \NetworkContext::get($bot);
    $nick = $args->nick;
    $chan = $args->chan;
    if(!isset($ctx->quoteRecordings[$nick])) {
        $bot->pm($chan, "You aren't doing a quote recording");
        return;
    }
    $bot->pm($chan, "Quote recording canceled");
    \Revolt\EventLoop::cancel($ctx->quoteRecordings[$nick]['timeOut']);
    unset($ctx->quoteRecordings[$nick]);
}

#[Cmd("querch", "findquote")]
#[Syntax("<query>...")]
#[Desc("Search for any quotes that match using * for wildcard")]
#[Option("--play", "Play the results up to limit")]
#[Option("--limit", "Limit amount played, default is 10")]
#[Option("--recent", "Order by most recent first")]
function searchquote(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
    $ctx = \NetworkContext::get($bot);
    $ctx->initQuotesDb();
    R::selectDatabase("quotes_{$ctx->name}");
    $query = "%" . str_replace("*", "%", $cmdArgs['query']) . "%";
    $order = "";
    if($cmdArgs->optEnabled("--recent")) {
        $order = "ORDER BY id DESC";
    }
    $limit = 10;
    if($cmdArgs->getOpt("--limit") !== false) {
        $limit = $cmdArgs->getOpt("--limit");
        if(!Validate::int($limit, 1)) {
            $bot->pm($args->chan, "--limit must be integer greater than 0");
            return;
        }
    }
    $quotes = R::find('quote', "data LIKE ? {$order} LIMIT $limit", [$query]);
    if(empty($quotes)) {
        $bot->pm($args->chan, "Nothing found");
        return;
    }
    if($cmdArgs->optEnabled("--play")) {
        $cnt = 0;
        foreach($quotes as $quote) {
            $cnt++;
            if($cnt > $limit) {
                \pumpToChan($bot, $args->chan, ["Limiting to $limit quotes..."]);
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
    $ids = implode(', ', $ids);
    $ids = "Found ($cnt) quotes: $ids";
    $ids = wordwrap($ids, 350);
    foreach(explode("\n", $ids) as $i) {
        $bot->pm($args->chan, $i);
    }
}

function initQuotes(\Irc\Client $bot, \NetworkContext $ctx): void {
    $ctx->initQuotesDb();
    $bot->on('chat', function ($args, \Irc\Client $bot) use ($ctx) {
        $nick = $args->from;
        $text = $args->text;
        if(!isset($ctx->quoteRecordings[$nick]))
            return;
        if($args->chan != $ctx->quoteRecordings[$nick]['chan'])
            return;
        \Revolt\EventLoop::cancel($ctx->quoteRecordings[$nick]['timeOut']);
        if(!$ctx->quoteRecordings[$nick]['keeptimes'])
            $text = stripTimestamp($text);
        $ctx->quoteRecordings[$nick]['lines'][] = $text;
        if(count($ctx->quoteRecordings[$nick]['lines']) > 100) {
            $bot->msg($ctx->quoteRecordings[$nick]['chan'], "$nick that quote sucks, keep it short");
            unset($ctx->quoteRecordings[$nick]);
            return;
        }
        $ctx->quoteRecordings[$nick]['timeOut'] = \Revolt\EventLoop::delay(15, fn() => quoteTimeOut($nick, $bot));
    });
}

#[Cmd("quote")]
#[Desc("Play a random quote or quote by id")]
#[Syntax("[id]...")]
#[Option("--contains", "Instead of id lookup find a random quote matching text")]
function cmd_quote(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
    $ctx = \NetworkContext::get($bot);
    $ctx->initQuotesDb();
    R::selectDatabase("quotes_{$ctx->name}");
    if(isset($cmdArgs['id'])) {
        if($cmdArgs->optEnabled("--contains")) {
            $search = "%" . trim($cmdArgs["id"]) . "%";
            $quote = R::findFromSQL("quote", "SELECT * FROM quote WHERE data LIKE ? ORDER BY RANDOM() LIMIT 1", [$search]);
            $quote = array_pop($quote);
        } else {
            $quote = R::findOne('quote', ' id = ? ', [$cmdArgs['id']]);
        }
        if($quote == null) {
            $bot->pm($args->chan, "Quote no matching quotes found.");
            return;
        }
    } else {
        $quote = R::findFromSQL("quote", "SELECT * FROM quote ORDER BY RANDOM() LIMIT 1");
        $quote = array_pop($quote);
    }
    showQuote($bot, $args->chan, $quote);
}

function showQuote(\Irc\Client $bot, string $chan, object $quote): void {
    $creator = $quote->creator;
    $header = "\2Quote {$quote['id']} recorded by {$creator['nick']} ({$creator['host']}) on {$quote['date']} in {$quote['chan']}";
    if(!is_string($quote['data'])) {
        $bot->pm($chan, "whoops something wrong with quote..");
        return;
    }
    $lines = explode("\n", $quote['data']);
    $lines = array_map(fn ($it) => "  $it", $lines);
    array_unshift($lines, $header);
    \pumpToChan($bot, $chan, $lines);
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
