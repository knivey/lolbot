<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

function kToF($temp) {
    return (1.8 * ($temp - 273)) + 32;
}

function kToC($temp) {
    return $temp - 273;
}

function displayTemp($temp) {
    return round(kToF($temp)) . '°F(' . round(kToC($temp)) . '°C)';
}

function windDir($deg) {
    $dirs = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"];
    return $dirs[round((($deg % 360) / 45))];
}

function weather($a, $bot, $chan)
{
    global $config;
    if (!isset($a[1])) {
        $bot->pm($chan, "give me something to lookup");
        return;
    }

    $fc = false;
    if ($a[0] == '.fc')
        $fc = true;
    unset($a[0]);
    $query = urlencode(htmlentities(implode(' ', $a)));
    //First we need lat lon
    $url = "http://dev.virtualearth.net/REST/v1/Locations/?key=$config[bingMapsKey]&o=json&query=$query&limit=1&language=$config[bingLang]";

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
        $j = json_decode($body, true);
        $res = $j['resourceSets'][0]['resources'];
        if (empty($res)) {
            $bot->pm($chan, "\2wz:\2 Location not found");
            return;
        }
        $location = $res[0]['address']['formattedAddress'];
        $lat = $res[0]['point']['coordinates'][0];
        $lon = $res[0]['point']['coordinates'][1];

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
        $tz = new DateTimeZone($j['timezone']);
        $fmt = "g:ia";
        $sunrise = new DateTime('@' . $cur['sunrise']);
        $sunrise->setTimezone($tz);
        $sunrise = $sunrise->format($fmt);
        $sunset = new DateTime('@' . $cur['sunset']);
        $sunset->setTimezone($tz);
        $sunset = $sunset->format($fmt);
        $temp = displayTemp($cur['temp']);
        if (!$fc) {
            $bot->pm($chan, "\2$location:\2 Currently " . $cur['weather'][0]['description'] . " $temp $cur[humidity]% humidity, UVI of $cur[uvi], wind direction " . windDir($cur['wind_deg']) . " at $cur[wind_speed] m/s Sun: $sunrise - $sunset");
        } else {
            $out = '';
            $cnt = 0;
            foreach ($j['daily'] as $d) {
                if ($cnt++ >= 4) break;
                $day = new DateTime('@' . $d['dt']);
                $day->setTimezone($tz);
                $day = $day->format('D');
                if ($cnt == 1) {
                    $day = "Today";
                }
                $tempMin = displayTemp($d['temp']['min']);
                $tempMax = displayTemp($d['temp']['max']);
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