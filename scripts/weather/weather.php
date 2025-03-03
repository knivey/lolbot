<?php
namespace scripts\weather;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Options;
use lolbot\entities\Network;
use scripts\script_base;
use scripts\weather\entities\location;
use function Symfony\Component\String\u;

class weather extends script_base
{
    /**
     * @phpstan-pure
     * @param mixed $temp 
     * @return float 
     */
    static function kToF($temp): float
    {
        return (1.8 * ($temp - 273)) + 32;
    }

    /**
     * @phpstan-pure
     * @param mixed $temp 
     * @return int|float 
     */
    static function kToC($temp)
    {
        return $temp - 273;
    }

    /**
     * @phpstan-pure
     * @param mixed $temp 
     * @param bool $si 
     * @return string 
     */
    static function displayTemp($temp, $si = false): string
    {
        if ($si)
            return round(self::kToC($temp)) . '°C';
        return round(self::kToF($temp)) . '°F';
    }

    /**
     * @phpstan-pure
     * @param mixed $speed 
     * @param bool $si 
     * @return string 
     */
    static function displayWindspeed($speed, $si = false): string
    {
        if ($si) {
            return "$speed m/s";
        }
        $speed = round($speed * 2.23694, 1);
        return "$speed mph";
    }

    /**
     * @phpstan-pure
     * @param mixed $deg 
     * @return string 
     */
    static function windDir($deg): string
    {
        $dirs = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"];
        return $dirs[round((($deg % 360) / 45)) % 8];
    }

    /**
     * @param $query
     * @return \Amp\Future<array{'location':string,'lat':string,'lon':string}|string>
     * @throws \async_get_exception
     */
    function getLocation($query): \Amp\Future
    {
        return \Amp\async(function () use ($query) {
            global $config;
            $query = urlencode($query);
            $url = "http://dev.virtualearth.net/REST/v1/Locations/?key=$config[bingMapsKey]&o=json&query=$query&limit=1&language=$config[bingLang]";
            $body = async_get_contents($url);

            $j = json_decode($body, true);
            $res = $j['resourceSets'][0]['resources'];
            if (empty($res)) {
                return "\2wz:\2 Location not found";
            }
            $location = $res[0]['address']['formattedAddress'];
            $lat = $res[0]['point']['coordinates'][0];
            $lon = $res[0]['point']['coordinates'][1];
            return ['location' => $location, 'lat' => $lat, 'lon' => $lon];
        });
    }


    #[Cmd("weather", "wz", "wea")]
    #[Syntax('[query]...')]
    #[Options("--si", "--metric", "--us", "--imperial", "--fc", "--forecast")]
    function weather($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $config, $entityManager;
        if (!isset($config['bingMapsKey'])) {
            echo "bingMapsKey not set in config\n";
            return;
        }
        if (!isset($config['bingLang'])) {
            echo "bingLang not set in config\n";
            return;
        }
        if (!isset($config['openweatherKey'])) {
            echo "openweatherKey not set in config\n";
            return;
        }
        $si = false;
        $fc = false;

        if (($cmdArgs->optEnabled("--si") || $cmdArgs->optEnabled("--metric")) &&
            ($cmdArgs->optEnabled("--us") || $cmdArgs->optEnabled("--imperial"))) {
            $bot->msg($args->chan, "Choose either si or imperial not both");
            return;
        }

        $query = $cmdArgs['query'] ?? '';
        if ($cmdArgs->optEnabled("--fc") || $cmdArgs->optEnabled("--forecast")) {
            $fc = true;
        }

        try {
            if ($query == '') {
                $nick = u($args->nick)->lower();
                $location = $entityManager->getRepository(location::class)->findOneBy(["nick" => $nick, "network" => $this->network]);
                if (!$location) {
                    $bot->msg($args->chan, "You don't have a location set use .setlocation");
                    return;
                }
                $si = $location->si;
            } else {
                if ($query[0] == '@') {
                    //lookup for another person's setlocation
                    $query = substr(u(explode(" ", $query)[0])->lower(), 1);
                    $location = $entityManager->getRepository(location::class)->findOneBy(["nick" => $query, "network" => $this->network]);
                    if (!$location) {
                        $bot->msg($args->chan, "$query does't have a location set");
                        return;
                    }
                    $si = $location->si;
                } else {
                    try {
                        $loc = self::getLocation($query)->await();
                    } catch (\async_get_exception $error) {
                        echo $error;
                        $bot->pm($args->chan, "\2wz:\2 {$error->getIRCMsg()}");
                        return;
                    }
                    if (!is_array($loc)) {
                        $bot->pm($args->chan, $loc);
                        return;
                    }
                    $location = new location();
                    $location->name = $loc['location'];
                    $location->lat = $loc['lat'];
                    $location->long = $loc['lon'];
                }
            }
            if ($cmdArgs->optEnabled("--si") || $cmdArgs->optEnabled("--metric")) {
                $si = true;
            }
            if ($cmdArgs->optEnabled("--us") || $cmdArgs->optEnabled("--imperial")) {
                $si = false;
            }

            //Now use lat lon to get weather

            $url = "https://api.openweathermap.org/data/3.0/onecall?lat={$location->lat}&lon={$location->long}&appid=$config[openweatherKey]&exclude=minutely,hourly";
            $body = async_get_contents($url);

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
                $tz = new \DateTimeZone("UTC");
            }
            $temp = self::displayTemp($cur['temp'], $si);
            $windSpeed = self::displayWindspeed($cur['wind_speed'], $si);
            if (!$fc) {
                $bot->pm($args->chan, "\2{$location->name}:\2 Currently " . $cur['weather'][0]['description'] . " $temp $cur[humidity]% humidity, UVI of $cur[uvi], wind " . self::windDir($cur['wind_deg']) . " at $windSpeed Sun: $sunrise - $sunset");
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
                    $tempMin = self::displayTemp($d['temp']['min'], $si);
                    $tempMax = self::displayTemp($d['temp']['max'], $si);
                    $w = $d['weather'][0]['main'];
                    $out .= "\2$day:\2 $w $tempMin/$tempMax $d[humidity]% humidity ";
                }
                $bot->pm($args->chan, "\2{$location->name}:\2 Forecast: $out");
            }
        } catch (\async_get_exception $error) {
            echo $error->getMessage();
            $bot->pm($args->chan, "\2wz:\2 {$error->getIRCMsg()}");
        } catch (\Exception $error) {
            echo $error->getMessage();
            $bot->pm($args->chan, "\2wz:\2 {$error->getMessage()}");
        }
    }

    #[Cmd("setlocation")]
    #[Syntax("<query>...")]
    #[Options("--si", "--metric")]
    function setlocation($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $entityManager;
        $si = false;
        if ($cmdArgs->optEnabled("--si") || $cmdArgs->optEnabled("--metric")) {
            $si = true;
        }

        try {
            $loc = self::getLocation($cmdArgs['query'])->await();
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

        $nick = u($args->nick)->lower();
        $location = $entityManager->getRepository(location::class)->findOneBy(["nick" => $nick, "network" => $this->network]);
        if (!$location) {
            $location = new location();
        }
        $location->name = $loc["location"];
        $location->lat = $loc["lat"];
        $location->long = $loc["lon"];
        $location->nick = $nick;
        $location->si = $si;
        $location->network = $this->network;
        $entityManager->persist($location);
        $entityManager->flush();

        $bot->msg($args->chan, "$nick your location is now set to $loc[location]");
    }
}