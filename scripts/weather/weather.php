<?php
namespace knivey\lolbot\weather;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use knivey\cmdr\attributes\CallWrap;

function kToF($temp) {
    return (1.8 * ($temp - 273)) + 32;
}

function kToC($temp) {
    return $temp - 273;
}

function displayTemp($temp, $si = false) {
    if($si)
        return round(kToC($temp)) . '°C';
    return round(kToF($temp)) . '°F';
}

function windDir($deg) {
    $dirs = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"];
    return $dirs[round((($deg % 360) / 45))];
}

function getLocation($query) {
    global $config;
    $query = urlencode(htmlentities($query));
    $url = "http://dev.virtualearth.net/REST/v1/Locations/?key=$config[bingMapsKey]&o=json&query=$query&limit=1&language=$config[bingLang]";
    $client = HttpClientBuilder::buildDefault();
    /** @var Response $response */
    $response = yield $client->request(new Request($url));
    $body = yield $response->getBody()->buffer();
    if ($response->getStatus() != 200) {
        var_dump($body);
        // Just in case its huge or some garbage
        $body = substr($body, 0, 200);
        return "Error (" . $response->getStatus() . ") $body";
    }
    $j = json_decode($body, true);
    $res = $j['resourceSets'][0]['resources'];
    if (empty($res)) {
        return "\2wz:\2 Location not found";
    }
    $location = $res[0]['address']['formattedAddress'];
    $lat = $res[0]['point']['coordinates'][0];
    $lon = $res[0]['point']['coordinates'][1];
    return ['location' => $location, 'lat' => $lat, 'lon' => $lon];
}


//Further work needed for --options for now parsing manually
//$router->add('(weather|wz) [(--fc | --forecast)] [(--si | --metric)] <query>...', 'weather');
#[Cmd("weather", "wz", "wea")]
#[Syntax('[query]...')]
#[CallWrap("Amp\asyncCall")]
function weather($nick, $chan, \Irc\Client $bot, cmdr\Request $req)
{
    global $config;
    if(!isset($config['bingMapsKey'])) {
        echo "bingMapsKey not set in config\n";
        return;
    }
    if(!isset($config['bingLang'])) {
        echo "bingLang not set in config\n";
        return;
    }
    if(!isset($config['openweatherKey'])) {
        echo "openweatherKey not set in config\n";
        return;
    }
    $si = false;
    $imp = false;
    $fc = false;
    $query = '';
    if(isset($req->args['query'])) {
        $query = $req->args['query'];
        if(str_contains($query, '--si') || str_contains($query, '--metric')) {
            $si = true;
        }
        if(str_contains($query, '--us') || str_contains($query, '--imperial')) {
            $imp = true;
        }
        if(str_contains($query, '--fc') || str_contains($query, '--forecast')) {
            $fc = true;
        }

        foreach(['--si', '--metric', '--fc', '--forecast', '--us', '--imperial'] as $rep) {
            $query = trim(str_replace($rep, '', $query));
            $query = str_replace('  ', ' ', $query);
        }
    }
    if($imp && $si) {
        $bot->msg($chan, "Choose either si or imperial not both");
        return;
    }

    try {
        if ($query == '') {
            $nick = strtolower($nick);
            $locs = unserialize(file_get_contents("weather.db"));
            if(!array_key_exists($nick, $locs)) {
                $bot->msg($chan, "You don't have a location set use .setlocation");
                return;
            }
            $location = $locs[$nick]['location'];
            $lat = $locs[$nick]['lat'];
            $lon = $locs[$nick]['lon'];
            $si = ($locs[$nick]['si'] or $si);
            if($imp) {
                $si = false;
            }
        } else {
            if($query[0] == '@') {
                //lookup for another person's setlocation
                $query = substr(strtolower(explode(" ", $query)[0]), 1);
                $locs = unserialize(file_get_contents("weather.db"));
                if(!array_key_exists($query, $locs)) {
                    $bot->msg($chan, "$query does't have a location set");
                    return;
                }
                $location = $locs[$query]['location'];
                $lat = $locs[$query]['lat'];
                $lon = $locs[$query]['lon'];
                $si = ($locs[$query]['si'] or $si);
                if($imp) {
                    $si = false;
                }
            } else {
                $loc = yield \Amp\call(__namespace__ . '\getLocation', $query);
                if (!is_array($loc)) {
                    $bot->pm($chan, $loc);
                    return;
                }
                $location = $loc['location'];
                $lat = $loc['lat'];
                $lon = $loc['lon'];
            }
        }
        //Now use lat lon to get weather

        $url = "https://api.openweathermap.org/data/2.5/onecall?lat=$lat&lon=$lon&appid=$config[openweatherKey]&exclude=minutely,hourly";

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
        $j = json_decode($body, true);
        $cur = $j['current'];
        try {
            $tz = new \DateTimeZone($j['timezone']);
            $fmt = "g:ia";
            $sunrise = new \DateTime('@' . $cur['sunrise']);
            $sunrise->setTimezone($tz);
            $sunrise = $sunrise->format($fmt);
            $sunset = new \DateTime('@' . $cur['sunset']);
            $sunset->setTimezone($tz);
            $sunset = $sunset->format($fmt);
        } catch (\Exception $e) {
            $sunrise = ''; $sunset = '';
        }
        $temp = displayTemp($cur['temp'], $si);
        if (!$fc) {
            $bot->pm($chan, "\2$location:\2 Currently " . $cur['weather'][0]['description'] . " $temp $cur[humidity]% humidity, UVI of $cur[uvi], wind direction " . windDir($cur['wind_deg']) . " at $cur[wind_speed] m/s Sun: $sunrise - $sunset");
        } else {
            $out = '';
            $cnt = 0;
            foreach ($j['daily'] as $d) {
                if ($cnt++ >= 4) break;
                $day = new \DateTime('@' . $d['dt']);
                $day->setTimezone($tz);
                $day = $day->format('D');
                if ($cnt == 1) {
                    $day = "Today";
                }
                $tempMin = displayTemp($d['temp']['min'], $si);
                $tempMax = displayTemp($d['temp']['max'], $si);
                $w = $d['weather'][0]['main'];
                $out .= "\2$day:\2 $w $tempMin/$tempMax $d[humidity]% humidity ";
            }
            $bot->pm($chan, "\2$location:\2 Forecast: $out");
        }
    } catch (HttpException $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($chan, "\2wz:\2" . $error);
    }
}

if(!file_exists("weather.db")) {
    file_put_contents("weather.db", serialize([]));
}

#[Cmd("setlocation")]
#[Syntax("<query>...")]
#[CallWrap("Amp\asyncCall")]
function setlocation($nick, $chan, \Irc\Client $bot, cmdr\Request $req)
{
    $query = $req->args['query'];

    $si = false;
    if(str_contains($query, '--si') || str_contains($query, '--metric')) {
        $si = true;
    }

    foreach(['--si', '--metric', '--fc', '--forecast', '--us', '--imperial'] as $rep) {
        $query = trim(str_replace($rep, '', $query));
        $query = str_replace('  ', ' ', $query);
    }

    if($query == '') {
        $bot->msg($chan, "you need a location too...");
        return;
    }

    $loc = yield \Amp\call(__namespace__ . '\getLocation', $query);
    if (!is_array($loc)) {
        $bot->pm($chan, $loc);
        return;
    }

    $nick = strtolower($nick);
    $locs = unserialize(file_get_contents("weather.db"));
    $locs[$nick] = $loc;
    $locs[$nick]['si'] = $si;
    file_put_contents("weather.db", serialize($locs));

    $bot->msg($chan, "$nick your location is now set to $loc[location]");
}
