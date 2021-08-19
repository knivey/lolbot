<?php
namespace scripts\weather;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Options;

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

/**
 * @param $query
 * @return array|\Generator|string
 * @throws \async_get_exception
 */
function getLocation($query) {
    global $config;
    $query = urlencode(htmlentities($query));
    $url = "http://dev.virtualearth.net/REST/v1/Locations/?key=$config[bingMapsKey]&o=json&query=$query&limit=1&language=$config[bingLang]";
    $body = yield async_get_contents($url);

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


#[Cmd("weather", "wz", "wea")]
#[Syntax('[query]...')]
#[CallWrap("Amp\asyncCall")]
#[Options("--si", "--metric", "--us", "--imperial", "--fc", "--forecast")]
function weather($args, \Irc\Client $bot, cmdr\Request $req)
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

    $query = $req->args['query'] ?? '';
    if($req->args->getOpt("--si") || $req->args->getOpt("--metric")) {
        $si = true;
    }
    if($req->args->getOpt("--us") || $req->args->getOpt("--imperial")) {
        $imp = true;
    }
    if($req->args->getOpt("--fc") || $req->args->getOpt("--forecast")) {
        $fc = true;
    }

    if($imp && $si) {
        $bot->msg($args->chan, "Choose either si or imperial not both");
        return;
    }

    try {
        if ($query == '') {
            $nick = strtolower($args->nick);
            $locs = unserialize(file_get_contents("weather.db"));
            if (!array_key_exists($nick, $locs)) {
                $bot->msg($args->chan, "You don't have a location set use .setlocation");
                return;
            }
            $location = $locs[$nick]['location'];
            $lat = $locs[$nick]['lat'];
            $lon = $locs[$nick]['lon'];
            $si = ($locs[$nick]['si'] or $si);
            if ($imp) {
                $si = false;
            }
        } else {
            if ($query[0] == '@') {
                //lookup for another person's setlocation
                $query = substr(strtolower(explode(" ", $query)[0]), 1);
                $locs = unserialize(file_get_contents("weather.db"));
                if (!array_key_exists($query, $locs)) {
                    $bot->msg($args->chan, "$query does't have a location set");
                    return;
                }
                $location = $locs[$query]['location'];
                $lat = $locs[$query]['lat'];
                $lon = $locs[$query]['lon'];
                $si = ($locs[$query]['si'] or $si);
                if ($imp) {
                    $si = false;
                }
            } else {
                try {
                    $loc = yield \Amp\call(__namespace__ . '\getLocation', $query);
                } catch (\async_get_exception $error) {
                    echo $error;
                    $bot->pm($args->chan, "\2wz:\2 {$error->getIRCMsg()}");
                    return;
                }
                if (!is_array($loc)) {
                    $bot->pm($args->chan, $loc);
                    return;
                }
                $location = $loc['location'];
                $lat = $loc['lat'];
                $lon = $loc['lon'];
            }
        }
        //Now use lat lon to get weather

        $url = "https://api.openweathermap.org/data/2.5/onecall?lat=$lat&lon=$lon&appid=$config[openweatherKey]&exclude=minutely,hourly";
        $body = yield async_get_contents($url);

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
            $sunrise = '';
            $sunset = '';
        }
        $temp = displayTemp($cur['temp'], $si);
        if (!$fc) {
            $bot->pm($args->chan, "\2$location:\2 Currently " . $cur['weather'][0]['description'] . " $temp $cur[humidity]% humidity, UVI of $cur[uvi], wind direction " . windDir($cur['wind_deg']) . " at $cur[wind_speed] m/s Sun: $sunrise - $sunset");
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
            $bot->pm($args->chan, "\2$location:\2 Forecast: $out");
        }
    } catch (\async_get_exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2wz:\2 {$error->getIRCMsg()}");
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error->getMessage();
        $bot->pm($args->chan, "\2wz:\2 {$error->getMessage()}");
    }
}

if(!file_exists("weather.db")) {
    file_put_contents("weather.db", serialize([]));
}

#[Cmd("setlocation")]
#[Syntax("<query>...")]
#[CallWrap("Amp\asyncCall")]
#[Options("--si", "--metric")]
function setlocation($args, \Irc\Client $bot, cmdr\Request $req)
{
    $si = false;
    if($req->args->getOpt("--si") || $req->args->getOpt("--metric")) {
        $si = true;
    }

    try {
        $loc = yield \Amp\call(__namespace__ . '\getLocation', $req->args['query']);
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2getLocation error:\2 {$error->getIRCMsg()}");
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2getLocation error:\2 {$error->getMessage()}");
        return;
    }
    if (!is_array($loc)) {
        $bot->pm($args->chan, $loc);
        return;
    }

    $nick = strtolower($args->nick);
    $locs = unserialize(file_get_contents("weather.db"));
    $locs[$nick] = $loc;
    $locs[$nick]['si'] = $si;
    file_put_contents("weather.db", serialize($locs));

    $bot->msg($args->chan, "$nick your location is now set to $loc[location]");
}
