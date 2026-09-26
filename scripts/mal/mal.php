<?php
namespace scripts\mal;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;

use simplehtmldom\HtmlDocument;
use simplehtmldom\HtmlNode;

use function knivey\tools\multi_array_padding;

class mal extends \scripts\script_base
{
    /**
     * MAL now 303-redirects exact-title searches straight to the entry page,
     * where the old div.title flow grabs related-entry manga cards instead of
     * the anime itself, and the mals results table is not there at all
     * (gh#131). Detect that landing and return the entry as a complete
     * search-result row; regular result-list pages return null and keep
     * using the old flow.
     *
     * @return array{id: string, url: string, title: string, type: string, eps: string, score: string}|null
     */
    public static function detectEntryPage(string $body): ?array
    {
        $doc = new HtmlDocument($body);
        $meta = $doc->find('meta[property=og:url]', 0);
        if (!$meta instanceof HtmlNode) {
            return null;
        }
        $ogUrl = $meta->getAttribute('content');
        if (!is_string($ogUrl)) {
            return null;
        }
        // og:url looks like https://myanimelist.net/anime/61192/Title_Slug
        $parts = explode('/', rtrim($ogUrl, '/'));
        if (count($parts) < 5 || $parts[3] !== 'anime' || !ctype_digit($parts[4])) {
            return null;
        }
        $id = $parts[4];
        $title = '';
        $h1 = $doc->find('h1.title-name', 0);
        if ($h1 instanceof HtmlNode) {
            $text = $h1->text();
            $title = is_string($text) ? trim($text) : '';
        }
        $type = '';
        $eps = '';
        foreach ((array) $doc->find('div.spaceit_pad') as $info) {
            if (!$info instanceof HtmlNode) {
                continue;
            }
            $span = $info->find('span', 0);
            if (!$span instanceof HtmlNode) {
                continue;
            }
            $label = $span->text();
            $text = $info->text();
            if (!is_string($label) || !is_string($text)) {
                continue;
            }
            $value = trim(substr($text, strlen($label) + 1));
            if ($label === 'Type:') {
                $type = $value;
            } elseif ($label === 'Episodes:') {
                $eps = $value;
            }
        }
        $score = '';
        $scoreNode = $doc->find('.score', 0);
        if ($scoreNode instanceof HtmlNode) {
            $text = $scoreNode->text();
            $score = is_string($text) ? trim($text) : '';
        }
        return ['id' => $id, 'url' => $ogUrl, 'title' => $title, 'type' => $type, 'eps' => $eps, 'score' => $score];
    }

    #[Cmd("mals", "myanimelistsearch")]
    #[Syntax("<search>...")]
    #[Desc("search a anime on myanimelist")]
    function mals(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        var_dump(urlencode($cmdArgs["search"]));
        $url = "https://myanimelist.net/anime.php?cat=anime&q=" . urlencode($cmdArgs["search"]);
        try {
            $body = async_get_contents($url);
        } catch (\async_get_exception $e) {
            $bot->pm($args->chan, "\2MAL:\2 {$e->getIRCMsg()}");
            return;
        }
        $results[] = ["ID", "Type", "Eps", "Title", "Score"];
        $entry = self::detectEntryPage($body);
        if ($entry !== null) {
            // exact-title searches redirect straight to the entry page, so
            // the results table is not there; show the entry as the single
            // result row
            $results[] = [$entry['id'], $entry['type'], $entry['eps'], $entry['title'], $entry['score']];
        } else {
            $doc = new HtmlDocument($body);

            $cnt = 0;
            foreach($doc->find('table', 1)->find('tr') as $tr) {
                $cnt++;
                if($cnt == 1)
                    continue;
                $id = $tr->find('td',0)->find('a', 0)?->getAttribute('href');
                preg_match("@^https?://myanimelist.net/anime/(\d+)/.*@",$id,$m);
                $id= $m[1];
                $title = trim($tr->find('td',1)->find('a', 0)?->text());

                $type =  $tr->find('td',2)->text();
                $eps = $tr->find('td',3)->text();
                $score = $tr->find('td',4)->text();
                $results[] = [$id, $type, $eps, $title, $score];
            }

            if(count($results) <= 1) {
                $bot->pm($args->chan, "\2MAL:\2 no results found");
                return;
            }
        }
        $results = array_slice($results, 0, 10);

        $results = multi_array_padding($results);
        $out = array_map(fn($v) => rtrim(implode($v)), $results);
        foreach($out as $line) {
            $bot->pm($args->chan, $line);
        }
    }

    #[Cmd("mal", "myanimelist")]
    #[Syntax("<search>...")]
    #[Desc("lookup a anime on myanimelist, search can be an ID to lookup directly")]
    function mal(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if(preg_match("/^\d+$/", $cmdArgs["search"])) {
            $result = "https://myanimelist.net/anime/{$cmdArgs['search']}";
        } else {
            $url = "https://myanimelist.net/anime.php?cat=anime&q=" . urlencode($cmdArgs["search"]);
            try {
                $body = async_get_contents($url);
            } catch (\async_get_exception $e) {
                $bot->pm($args->chan, "\2MAL:\2 {$e->getIRCMsg()}");
                return;
            }
            $entry = self::detectEntryPage($body);
            if ($entry !== null) {
                // exact-title searches redirect straight to the entry page
                // (gh#131); reuse the body we already have
                $this->showDetail($args, $bot, $entry['url'], $body);
                return;
            }
            $doc = new HtmlDocument($body);
            $result = $doc->find('div.title', 0)?->find('a', 0)?->getAttribute('href');
            foreach ($doc->find('div.title') as $e) {
                if (strtolower($e->find('a', 0)?->text()) == strtolower($cmdArgs["search"]))
                    $result = $e->find('a', 0)?->getAttribute('href');
            }

            if (!$result) {
                $bot->pm($args->chan, "\2MAL:\2 no results found");
                return;
            }
        }
        $this->showDetail($args, $bot, $result);
    }

    private function showDetail(\Irc\Event\ChatEvent $args, \Irc\Client $bot, string $result, ?string $body = null): void
    {
        try {
            $body ??= async_get_contents($result);
        } catch (\async_get_exception $e) {
            if($e->getCode() == 404)
                $bot->pm($args->chan, "\2MAL:\2 404 anime not found");
            else
                $bot->pm($args->chan, "\2MAL:\2 {$e->getIRCMsg()}");
            return;
        }
        $p = new HtmlDocument($body);
        $name = $p->find('.title-name',0)?->text();
        $name_eng =$p->find('.title-english',0)?->text();
        $score = $p->find('.score',0)?->text();
        $score_users = $p->find('.score',0)?->getAttribute('data-user');
        $desc = str_replace("\r", "", $p->find('p[itemprop=description]',0)?->text());
        // throttled nets: wrap near the irc line limit so output takes less lines (gh#133)
        if ($this->server->throttle)
            $desc = wordwrap($desc, 350);
        else
            $desc = wordwrap($desc);

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
            if($i->find('span', 0)?->text() == "Genre:") {
                $genres = "\2Genre:\2 " . $i->find('a', 0)?->text();
            }
            if($i->find('span', 0)?->text() == "Theme:") {
                $themes = "\2Theme:\2 " . $i->find('a', 0)?->text();
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
}
