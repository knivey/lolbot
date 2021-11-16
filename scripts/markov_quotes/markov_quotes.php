<?php
namespace scripts\markov_quotes;
/*
 * This is to have some fun with using a quotes.txt file in a channel to make up new quotes using markov
 */

global $config;
if(!isset($config['markov_quotefile']) || !is_file($config['markov_quotefile']))
    return;

use \Decidedly\TextGenerators\SimpleMarkovGenerator;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr;

$markov = new SimpleMarkovGenerator(2);

$markov->parseText(file_get_contents($config['markov_quotefile']));

#[Cmd("mquote")]
function mquote($args, \Irc\Client $bot, cmdr\Request $req)
{
    global $markov;
    $text = "";
    $tries = 0;
    while($text == "" && $tries++ < 100) {
        $text = $markov->generateText(rand(80, 300), rand(15, 30), 1);
        $text = preg_replace("/^[^<]+/", '', $text);
        $last = strrpos($text, "<");
        if ($last) {
            $text = substr($text, 0, $last);
        }
    }
    $bot->pm($args->chan, ($text == "" ? "Failed to make quote" : $text));
}
