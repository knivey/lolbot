<?php
namespace knivey\lolbot\lastfm;

require_once 'library/Duration.inc';
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

#[Cmd("lastfm")]
#[Syntax('[user]')]
#[CallWrap("Amp\asyncCall")]
function lastfm($nick, $chan, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    $key = $config['lastfm'] ?? false;
    if(!$key) {
        echo "lastfm key not set on config\n";
        return;
    }

    if (isset($req->args['user'])) {
        $user = $req->args['user'];
    } else {
        $user = $nick;
    }
    $user = urlencode(htmlentities($user));
    $url = "http://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&user=$user&api_key=$key&format=json&limit=1";
    try {
        $client = HttpClientBuilder::buildDefault();
        /** @var Response $response */
        $response = yield $client->request(new Request($url));
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = substr($body, 0, 200);
            $bot->pm($chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }
    } catch (HttpException $error) {
        echo $error;
        $bot->pm($chan, "\2lastfm:\2" . $error);
    }
    $res = json_decode($body, true);
    if(!isset($res['recenttracks']['track'][0])) {
        $bot->pm($chan, "Failed to find any recent tracks.");
        return;
    }
    //Fix case :)
    $user = $res['recenttracks']['@attr']['user'] ?? $user;
    $track = $res['recenttracks']['track'][0];
    $title = $track['name'] ?? 'No Title';
    $artist = $track['artist']['#text'] ?? 'Unknown Artist';
    $album = $track['album']['#text'] ?? 'Unknown Album';
    $time = 'scrobbling now';
    if(isset($track['date']['uts'])) {
        $ago = time() - $track['date']['uts'];
        $dur = Duration_int2array($ago);
        $ago = Duration_array2string(array_slice($dur, 0, 3), 1);
        $time = "last scrobbled $ago ago";
    }
    $bot->pm($chan, "\2last.fm:\2 $user $time: $title - $album - $artist");
}