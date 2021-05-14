<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

$link_history = [];
$link_ratelimit = 0;
function linktitles(\Irc\Client $bot, $chan, $text)
{
    global $link_history, $link_ratelimit;
    foreach(explode(' ', $text) as $word) {
        if (filter_var($word, FILTER_VALIDATE_URL) === false) {
            continue;
        }
        //Skip youtubes
        $URL = '/^((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?$/';
        if (preg_match($URL, $word)) {
            continue;
        }

        if(($link_history[$chan] ?? "") == $word) {
            continue;
        }
        $link_history[$chan] = $word;

        if(time() < $link_ratelimit) {
            return;
        }
        $link_ratelimit = time() + 2;

        //Handle github user or project
        if(preg_match("@https?://(?:www\.)?github\.com/([^/]+)(?:/([^/]+))?/?@i", $word, $m)) {
            $user = $m[1];
            $repo = $m[2] ?? null;
            if($out = yield from github($user, $repo)) {
                $bot->pm($chan, $out);
                continue;
            }
        }

        try {
            $client = HttpClientBuilder::buildDefault();
            $req = new Request($word);
            $req->setTransferTimeout(4000);
            $req->setBodySizeLimit(1024 * 1024 * 8);
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                $bot->pm($chan, "LinkTitles Error (" . $response->getStatus() . ") " . substr($body, 0, 200));
                //var_dump($body);
                return;
            }

            if(!preg_match("/<title[^>]*>([^<]+)<\/title>/im", $body, $m)) {
                continue;
            }

            $title = strip_tags($m[1]);
            $title = html_entity_decode($title,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = htmlspecialchars_decode($title);
            $title = str_replace("\n", " ", $title);
            $title = str_replace("\r", " ", $title);
            $title = str_replace("\x01", "[CTCP]", $title);
            $title = substr(trim($title), 0, 300);
            $bot->pm($chan, "[ $title ]");
        } catch (\Exception $error) {
            echo "Link titles exception: $error\n";
        }
    }
}

function github($user, $repo) {
    if($repo != null)
        return yield from github_repo($user, $repo);
    $url = "https://api.github.com/users/$user"; 
    try {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        /** @var Response $response */
        $response = yield $client->request($req);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            echo "Github $url lookup error: {$response->getStatus()}\n";
            var_dump($body);
            return false;
        }
        $body = json_decode($body, true);
        $hireable = "false";
        if($body['hireable'])
            $hireable = "true";
        $created = reftime($body["created_at"]);
        $stars = yield from github_stars($user);
        return "\x02[GitHub]\x02 $body[login] \x02Name:\x02 $body[name] \x02Stars:\x02 $stars \x02Created:\x02 $created \x02Location:\x02 $body[location] \x02Hireable:\x02 $hireable \x02Public Repos:\x02 $body[public_repos] \x02Followers:\x02 $body[followers] \x02Bio:\x02 $body[bio]";
    } catch (\Exception $error) {
        echo "Github $url exception: $error\n";
        return false;
    }
}

function github_stars($user) {
    $url = "https://api.github.com/users/$user/repos";
    try {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        /** @var Response $response */
        $response = yield $client->request($req);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            echo "Github $url lookup error: {$response->getStatus()}\n";
            var_dump($body);
            return false;
        }
        $body = json_decode($body, true);
        $stars = 0;
        foreach ($body as $repo) {
            $stars += $repo["stargazers_count"];
        }
        return $stars;
    } catch (\Exception $error) {
        echo "Github $url exception: $error\n";
        return false;
    }
}

function github_repo($user, $repo) {
    $url = "https://api.github.com/repos/$user/$repo";
    try {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        /** @var Response $response */
        $response = yield $client->request($req);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            echo "Github $url lookup error: {$response->getStatus()}\n";
            var_dump($body);
            return false;
        }
        $body = json_decode($body, true);
        $langs = yield from github_langs($user, $repo);
        $pushed_at = reftime($body["pushed_at"]);
        $out =  "\x02[GitHub]\x02 $body[full_name] ";
        if($body['fork']) {
            $out .= "(Fork of {$body["parent"]["full_name"]}) ";
        }
        return "{$out}- $body[description] | $langs | \x02Stargazers:\x02 $body[stargazers_count] \x02Watchers:\x02 $body[watchers_count] \x02Forks:\x02 $body[forks] \x02Open Issues:\x02 $body[open_issues] \x02Last Push:\x02 $pushed_at";
    } catch (\Exception $error) {
        echo "Github $url exception: $error\n";
        return false;
    }
}

function reftime($time) {
    try {
        $out = strtotime($time);
        return strftime("%r %F %Z", $out);
    } catch (\Exception $e) {
        return $time;
    }
}

function github_langs($user, $repo) {
    $url = "https://api.github.com/repos/$user/$repo/languages";
    try {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        /** @var Response $response */
        $response = yield $client->request($req);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            echo "Github $url lookup error: {$response->getStatus()}\n";
            var_dump($body);
            return false;
        }
        $body = json_decode($body, true);
        $total = array_sum($body);
        $colors = ["12", "08", "09", "13"];
        $cnt = 0;
        $langs = [];
        $percentCnt = 0;
        foreach ($body as $lang => $lines) {
            if(!isset($colors[$cnt]))
                break;
            $percentCnt += $percent = round($lines / $total) * 100;
            if($cnt == 3)
                $langs[] = "\x03". $colors[$cnt] . (100 - $percentCnt) . "% Other";
            else
                $langs[] = "\x03". $colors[$cnt] . "{$percent}% $lang";
            $cnt++;
        }

        return implode(" ", $langs) . "\x0F";
    } catch (\Exception $error) {
        echo "Github $url exception: $error\n";
        return false;
    }
}