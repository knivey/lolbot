<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

function github($user, $repo) {
    global $config;
    if($repo != null)
        return yield from github_repo($user, $repo);
    $url = "https://api.github.com/users/$user";
    try {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        if(isset($config['github_auth']))
            $req->addHeader('Authorization', 'Basic ' . base64_encode($config['github_auth']));
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
    global $config;
    $page = 1;
    $stars = 0;
    $url = "https://api.github.com/users/$user/repos?per_page=100&page={${'page'}}";
    try {
        do {
            $client = HttpClientBuilder::buildDefault();
            $req = new Request($url);
            if(isset($config['github_auth']))
                $req->addHeader('Authorization', 'Basic ' . base64_encode($config['github_auth']));
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                echo "Github $url lookup error: {$response->getStatus()}\n";
                var_dump($body);
                return false;
            }
            $body = json_decode($body, true);
            if(!is_array($body)) {
                var_dump($body);
                return 0;
            }
            foreach ($body as $repo) {
                if(!isset($repo["stargazers_count"])) {
                    echo "github stars stargazers_count missing??\n";
                    var_dump($repo);
                    continue;
                }
                $stars += $repo["stargazers_count"];
            }
            $page++;
        } while (count($body) == 100 && $page < 10);
        //Dont want to blow out our limits ^
        return $stars;
    } catch (\Exception $error) {
        echo "Github $url exception: $error\n";
        return false;
    }
}

function github_repo($user, $repo) {
    global $config;
    $url = "https://api.github.com/repos/$user/$repo";
    try {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        if(isset($config['github_auth']))
            $req->addHeader('Authorization', 'Basic ' . base64_encode($config['github_auth']));
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
    global $config;
    $url = "https://api.github.com/repos/$user/$repo/languages";
    try {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        if(isset($config['github_auth']))
            $req->addHeader('Authorization', 'Basic ' . base64_encode($config['github_auth']));
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
            $percentCnt += $percent = round(($lines / $total) * 100, 1);
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