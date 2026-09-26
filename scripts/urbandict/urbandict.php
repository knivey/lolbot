<?php
namespace scripts\urbandict;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

use simplehtmldom\HtmlDocument;
use simplehtmldom\HtmlNode;

//TODO --amt for ud

class urbandict extends \scripts\script_base
{
    /**
     * Parse a urbandictionary define page into definition data.
     *
     * 2026 site markup: the exact-term def is an article.group.definition while
     * feed defs are div.definition (matched together via .definition), the word
     * is span.word (+ data-word attr on the container), the byline lives in
     * div.font-medium, and Word of the Day entries are flagged by a
     * text-gray-600 line. (gh#132)
     *
     * @return array<int, array{word: string, meaning: string, example: string, by: string, wotd: bool}>
     */
    public static function parseDefs(string $body): array
    {
        $doc = new HtmlDocument($body);
        $defs = @$doc->find('.definition');
        if (!is_array($defs) || count($defs) < 1) {
            return [];
        }
        $out = [];
        foreach ($defs as $def) {
            if (!$def instanceof HtmlNode) {
                continue;
            }
            $out[] = self::parseDef($def);
        }
        return $out;
    }

    /**
     * @return array{word: string, meaning: string, example: string, by: string, wotd: bool}
     */
    private static function parseDef(HtmlNode $def): array
    {
        $word = $def->getAttribute('data-word');
        if (!is_string($word) || $word === '') {
            $word = self::nodeText($def, 'span.word');
        }
        $author = self::nodeAttr($def, 'a[data-grow-author]', 'data-grow-author');
        $date = '';
        if (preg_match('/([A-Z][a-z]+ \d{1,2}, \d{4})\s*$/D', self::nodeText($def, 'div.font-medium'), $m)) {
            $date = $m[1];
        }
        return [
            'word' => html_entity_decode($word, ENT_QUOTES | ENT_HTML5),
            'meaning' => html_entity_decode(self::nodeText($def, 'div.meaning'), ENT_QUOTES | ENT_HTML5),
            'example' => html_entity_decode(self::nodeText($def, 'div.example'), ENT_QUOTES | ENT_HTML5),
            'by' => html_entity_decode(trim("{$author} {$date}"), ENT_QUOTES | ENT_HTML5),
            'wotd' => str_contains(self::nodeText($def, 'div.text-gray-600'), 'Word of the Day'),
        ];
    }

    /**
     * Pick the first $max non-WOTD defs in order (WOTD entries are stuffed
     * into the feed). If every def is WOTD — e.g. the term itself was a Word
     * of the Day — fall back to the first def so output is never empty.
     *
     * @param array<int, array{word: string, meaning: string, example: string, by: string, wotd: bool}> $defs
     * @return array<int, array{word: string, meaning: string, example: string, by: string, wotd: bool}>
     */
    public static function selectDefs(array $defs, int $max): array
    {
        if ($max < 1) {
            return [];
        }
        $out = [];
        foreach ($defs as $def) {
            if (count($out) >= $max) {
                break;
            }
            if ($def['wotd']) {
                continue;
            }
            $out[] = $def;
        }
        if ($out === [] && $defs !== []) {
            $out[] = $defs[0];
        }
        return $out;
    }

    private static function nodeText(HtmlNode $node, string $selector): string
    {
        $found = $node->find($selector, 0);
        if (!$found instanceof HtmlNode) {
            return '';
        }
        $text = $found->text();
        return is_string($text) ? $text : '';
    }

    private static function nodeAttr(HtmlNode $node, string $selector, string $attr): string
    {
        $found = $node->find($selector, 0);
        if (!$found instanceof HtmlNode) {
            return '';
        }
        $value = $found->getAttribute($attr);
        return is_string($value) ? $value : '';
    }

    #[Cmd("ud", "urban", "urbandict")]
    #[Syntax('<query>...')]
    function ud(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        $query = urlencode($cmdArgs['query']);
        try { 
            $body = async_get_contents("http://www.urbandictionary.com/define.php?term=$query");
        } catch (\async_get_exception $e) {
            // we get a 404 if word not found
            if ($e->getCode() == 404) {
                $bot->msg($args->chan, "ud: There are no definitions for this word.");
            } else {
                echo $e->getCode() . ' ' . $e->getMessageStripped();
                $bot->msg($args->chan, "ud: Problem getting data from urbandictionary");
            }
            return;
        }
        if (str_contains($body, "<div class=\"term space\">Sorry, we couldn't find:")) {

            return;
        }
        $defs = self::parseDefs($body);

        // wonder if this would happen after that earlier check?
        if (count($defs) < 1) {
            $bot->msg($args->chan, "ud: Couldn't find an entry matching {$cmdArgs['query']}");
            return;
        }

        $max = 2;
        if ($this->server->throttle)
            $max = 1;
        $num = 0;
        foreach (self::selectDefs($defs, $max) as $def) {
            $num++;
            $meaning = $def['meaning'];
            $example = $def['example'];
            $word = $def['word'];
            $by = $def['by'];

            $meaning = trim(str_replace(["\n", "\r"], ' ', $meaning));

            $example = str_replace("\r", "\n", $example);
            $example = explode("\n", $example);
            $example = array_map('trim', $example);
            $example = array_filter($example);
            $example1line = implode(' | ', $example);

            $bot->msg($args->chan, "ud: $word #$num added $by");
            if ($this->server->throttle) {
                // still wrap on throttle, just near the irc line limit so its less lines (gh#133)
                $lines = explode("\n", wordwrap($meaning, 350));
                foreach ($lines as $li => $m) {
                    $leader = $li == 0 ? "├ Meaning: " : "│ ";
                    $bot->msg($args->chan, " $leader $m");
                }
                $lines = explode("\n", wordwrap($example1line, 350));
                $last = count($lines) - 1;
                foreach ($lines as $li => $el) {
                    $leader = $li == $last ? "└ " : "│ ";
                    $bot->msg($args->chan, " $leader $el");
                }
            } else {
                $c = 0;
                foreach (explode("\n", wordwrap($meaning, 80)) as $m) {
                    $leader = $c > 0 ? "│ " : "├ ";
                    $bot->msg($args->chan, " $leader $m");
                    $c++;
                }
                $c = 0;
                foreach ($example as $e) {
                    $leader = $c > 0 ? "│ " : "├ ";
                    if ($c == count($example) - 1)
                        $leader = "└ ";
                    $bot->msg($args->chan, " $leader   $e");
                    $c++;
                }
            }
        }
    }
}