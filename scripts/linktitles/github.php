<?php
namespace scripts\linktitles;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

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