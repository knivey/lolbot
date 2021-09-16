<?php
namespace scripts\github;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Irc\Exception;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use League\Uri\Uri;
use scripts\linktitles\UrlEvent;

global $eventProvider;
$eventProvider->addListener(
    function (UrlEvent $event) {
        if ($event->handled)
            return;
        $event->promises[] = \Amp\call(function() use ($event) {
            //Handle github user or project
            $uri = Uri::createFromString($event->url);
            //array_values to make sure its indexed if anything removed with filter
            $pathParts = array_values(array_filter(explode('/', $uri->getPath())));
            //var_dump($word, $uri, $uri->getPath(), $pathParts);
            if (preg_match("@^(?:www\.)?github\.com$@i", $uri->getHost())) {
                if(!isset($pathParts[0]))
                    return;
                $user = $pathParts[0];
                //ignore site paths, probably more exists than these
                if (in_array(strtolower($user), [
                    'pulls', 'issues', 'marketplace', 'explore', 'notifications', 'new', 'organizations', 'codespaces',
                    'account', 'settings', 'security', 'pricing', 'about'
                ]))
                    return;
                $repo = $pathParts[1] ?? null;
                $repoAction = $pathParts[2] ?? null;
                if ($repoAction == null) {
                    if ($out = yield github($user, $repo)) {
                        $event->reply($out);
                        return;
                    }
                }
                // in the future we can handle issues etc here
                // bot for now it falls through like any normal url title
                $repoParts = array_slice($pathParts, 3);
                if (strtolower($repoAction) == 'issues' && isset($repoParts[0])) {
                    if ($out = yield github_issueStr($user, $repo, $repoParts[0])) {
                        $event->reply($out);
                        return;
                    }
                }
            }
        });
    }
);

#[Cmd("gh", "github")]
#[Syntax("<user/repo>")]
#[CallWrap("Amp\asyncCall")]
function github_cmd($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $query = $req->args['user/repo'];
    if(!preg_match("@^([^/]+)(?:/([^/]+))?$@", $query, $m)) {
        $bot->pm($args->chan, "\x02[GitHub]\x02 Query wasn't recognized, give me user or user/repo");
        return;
    }
    $user = $m[1];
    $repo = $m[2] ?? null;
    try {
        $out = yield github($user, $repo);
        if(!$out) {
            $bot->pm($args->chan, "\x02[GitHub]\x02 nothing found or server error :(");
            return;
        }
        $bot->pm($args->chan, $out);
    } catch (\async_get_exception $e) {
        $bot->pm($args->chan, "\x02[GitHub]\x02 Error: {$e->getIRCMsg()}");
    }
    catch (Exception $e) {
        $bot->pm($args->chan, "\x02[GitHub]\x02 Error: {$e->getMessage()}");
    }
}

//TODO throw exceptions instead of just return false
function github_get_json($url, $assoc = false) {
    return \Amp\call(function() use ($url, $assoc) {
        try {
            $client = HttpClientBuilder::buildDefault();
            $req = new Request($url);
            if (isset($config['github_auth']))
                $req->addHeader('Authorization', 'Basic ' . base64_encode($config['github_auth']));
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                echo "Github $url lookup error: {$response->getStatus()}\n";
                var_dump($body);
                return false;
            }
            $body = json_decode($body, $assoc);
            return $body;
        } catch (\Exception $error) {
            echo "Github $url exception: $error\n";
            return false;
        }
    });
}

function github($user, $repo) {
    return \Amp\call(function () use ($user, $repo) {
        if ($repo != null)
            return yield github_repo($user, $repo);
        $url = "https://api.github.com/users/$user";
        if ($json = yield github_get_json($url, true)) {
            $hireable = "false";
            if ($json['hireable'])
                $hireable = "true";
            $created = reftime($json["created_at"]);
            $stars = yield github_stars($user);
            return "\x02[GitHub]\x02 $json[login] \x02Name:\x02 $json[name] \x02Stars:\x02 $stars \x02Created:\x02 $created \x02Location:\x02 $json[location] \x02Hireable:\x02 $hireable \x02Public Repos:\x02 $json[public_repos] \x02Followers:\x02 $json[followers] \x02Bio:\x02 $json[bio]";
        }
        return false;
    });
}

function github_issueStr($user, $repo, $issue) {
    return \Amp\call(function() use ($user, $repo, $issue) {
        $url = "https://api.github.com/repos/$user/$repo/issues/$issue";
        if(!$json = yield github_get_json($url)) {
            return false;
        }
        if(!isset($json->user) || !isset($json->title) || !isset($json->state))
            return false;
        $user = $json->user;
        return "\x02[GitHub]\x02 {$user->login}/$repo Issue({$json->state}): {$json->title}";
    });
}

function github_stars($user) {
    return \Amp\call(function() use ($user) {
        $page = 1;
        $stars = 0;
        $url = "https://api.github.com/users/$user/repos?per_page=100&page={${'page'}}";
        //var_dump($url);
        do {
            $body = yield github_get_json($url, true);
            if (!is_array($body)) {
                return 0;
            }
            foreach ($body as $repo) {
                if (!isset($repo["stargazers_count"])) {
                    continue;
                }
                $stars += $repo["stargazers_count"];
            }
            $page++;
        } while (count($body) == 100 && $page < 10); //TODO just say lots if over the limit?
        //Dont want to blow out our limits ^
        return $stars;
    });
}

function github_repo($user, $repo) {
    return \Amp\call(function() use ($user, $repo) {
        $url = "https://api.github.com/repos/$user/$repo";
        $body = yield github_get_json($url, true);
        $langs = yield github_langs($user, $repo);
        if(!$body)
            return false;
        $pushed_at = reftime($body["pushed_at"]);
        $out = "\x02[GitHub]\x02 $body[full_name] ";
        if ($body['fork']) {
            $out .= "(Fork of {$body["parent"]["full_name"]}) ";
        }
        return "{$out}- $body[description] | $langs | \x02Stargazers:\x02 $body[stargazers_count] \x02Watchers:\x02 $body[watchers_count] \x02Forks:\x02 $body[forks] \x02Open Issues:\x02 $body[open_issues] \x02Last Push:\x02 $pushed_at";
    });
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
    return \Amp\call(function() use($user, $repo) {
        $url = "https://api.github.com/repos/$user/$repo/languages";
        $body = yield github_get_json($url, true);
        if(!$body)
            return "Unknown Languages";
        $total = array_sum($body);
        $colors = ["12", "08", "09", "13"];
        $cnt = 0;
        $langs = [];
        $percentCnt = 0;
        foreach ($body as $lang => $lines) {
            if (!isset($colors[$cnt]))
                break;
            $percentCnt += $percent = round(($lines / $total) * 100, 1);
            if ($cnt == 3)
                $langs[] = "\x03" . $colors[$cnt] . round((100 - $percentCnt), 1) . "% Other";
            else
                $langs[] = "\x03" . $colors[$cnt] . "{$percent}% $lang";
            $cnt++;
        }

        return implode(" ", $langs) . "\x0F";
    });
}