<?php
namespace scripts\weather;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\HttpClient;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Options;
use library\settings\Setting;
use library\settings\SettingsStore;
use library\user\ResolveContext;
use library\user\UserSystem;
use lolbot\entities\Network;
use scripts\script_base;
use scripts\weather\entities\location;

use function knivey\tools\microtime_float;
use function Symfony\Component\String\u;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

#[Setting('weather.location', type: 'string', default: '', scope: 'account', flag: 'admin', description: 'account-level location override for .weather')]
function weather_account_location_setting(): void
{
}

/*
 * The account tier of .weather's layered location read: resolves the
 * speaking nick through the client's user-system bundle (allowCreate
 * FALSE — weather must never auto-register an identity) and returns the
 * weather.location user setting when it holds a non-empty string, as a
 * free-text query for the caller to geocode exactly like an explicit
 * .weather <query>. Every miss — no bundle wired, identity unresolved,
 * setting at its '' default, or any error in the lookup — returns null
 * so the nick-keyed setlocation row decides as before: the override is
 * a pure add-on and must never break weather for users without the
 * user system.
 */
function weather_location_for(?UserSystem $us, \Irc\Event\UserEvent $args): ?string
{
    if ($us === null) {
        return null;
    }
    try {
        $hit = $us->svc->resolve(new ResolveContext(
            networkId: $us->netId(),
            nick: $args->nick,
            nickLowered: mb_strtolower($args->nick),
            identHost: $args->identhost,
            account: $args->account,
            client: $args->sender,
            allowCreate: false,
        ));
        if ($hit === null) {
            return null;
        }
        $store = new SettingsStore($us->em);
        $got = $store->getUserSetting($hit['user_id'], 'weather.location');
        $value = $got['value'];
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    } catch (\Throwable) {
        return null;
    }
}

class weather extends script_base
{

    static FilesystemAdapter $location_cache;
    static float $last_location_time = 0;

    public function init(): void
    {
        self::$location_cache = new FilesystemAdapter('weather');
    }

    /**
     * @phpstan-pure
     * @param mixed $temp 
     * @return float microtime_float() - $last_location_time
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
     * @param int|float $speed 
     * @param bool $si 
     * @return string 
     */
    static function displayWindspeed(int|float $speed, bool $si = false): string
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
     * @phpstan-pure
     * @param array $hour Single entry from OpenWeatherMap hourly array
     * @param \DateTimeZone $tz Timezone for time formatting
     * @param bool $si Metric units
     * @param bool $detailed Show wind/humidity/precip
     * @return string Formatted entry string
     */
    static function formatHourlyEntry(array $hour, \DateTimeZone $tz, bool $si, bool $detailed): string
    {
        $time = new \DateTime('@' . $hour['dt']);
        $time->setTimezone($tz);
        $timeStr = $time->format('ga');
        $condition = ucfirst($hour['weather'][0]['description']);
        $temp = self::displayTemp($hour['temp'], $si);
        $entry = "\2$timeStr:\2 $condition $temp";
        if ($detailed) {
            $wind = self::windDir($hour['wind_deg']) . self::displayWindspeed($hour['wind_speed'], $si);
            $humidity = $hour['humidity'];
            $pop = round($hour['pop'] * 100);
            $entry .= " $wind {$humidity}%h {$pop}%p";
        }
        return $entry;
    }

    /**
     * @param $query
     * @return \Amp\Future<array{'location':string,'lat':string,'lon':string}|string>
     * @throws \async_get_exception
     */
    function getLocation(string $query): \Amp\Future
    {
        return \Amp\async(function () use ($query) {
            if (preg_match('/^\d{5}$/', $query)) {
                $query .= ', usa';
            }
            $loc = self::$location_cache->getItem($query);
            if(!$loc->isHit()) {
                while (microtime_float() - self::$last_location_time < 2) {
                    $delay = 2 - (microtime_float() - self::$last_location_time);
                    $this->logger->info("delaying lookup by $delay");
                    \Amp\delay($delay);
                }
                self::$last_location_time = microtime_float();
                $query = urlencode($query);
                $url = "https://nominatim.openstreetmap.org/search?q=$query&format=json";
                $client = HttpClientBuilder::buildDefault();
                $request = new Request($url);
                $request->setHeader("User-Agent", "lolbot irc weather bot");
                $response = $client->request($request);
                $body =  $response->getBody()->buffer();
                if ($response->getStatus() != 200) {
                    throw new \async_get_exception($body, $response->getStatus());
                }

                $res = json_decode($body, true);
                if (!is_array($res)) {
                    return "\2wz:\2 Location service error";
                }
                if (count($res) == 0) {
                    return "\2wz:\2 Location not found";
                }
                $loc->set($res);
                self::$location_cache->save($loc);
            } else {
                $res = $loc->get();
            }
            $location = $res[0]['display_name'];
            $lat = $res[0]['lat'];
            $lon = $res[0]['lon'];
            return ['location' => $location, 'lat' => $lat, 'lon' => $lon];
        });
    }


    #[Cmd("weather", "wz", "wea")]
    #[Syntax('[query]...')]
    #[Options("--si", "--metric", "--us", "--imperial", "--fc", "--forecast", "--hourly", "--hr", "--detailed", "--d")]
    function weather(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
        $hourly = false;

        if (($cmdArgs->optEnabled("--si") || $cmdArgs->optEnabled("--metric")) &&
            ($cmdArgs->optEnabled("--us") || $cmdArgs->optEnabled("--imperial"))) {
            $bot->msg($args->chan, "Choose either si or imperial not both");
            return;
        }

        if (($cmdArgs->optEnabled("--fc") || $cmdArgs->optEnabled("--forecast")) &&
            ($cmdArgs->optEnabled("--hourly") || $cmdArgs->optEnabled("--hr"))) {
            $bot->msg($args->chan, "Choose either --fc or --hourly not both");
            return;
        }

        $query = $cmdArgs['query'] ?? '';
        if ($cmdArgs->optEnabled("--fc") || $cmdArgs->optEnabled("--forecast")) {
            $fc = true;
        }
        if ($cmdArgs->optEnabled("--hourly") || $cmdArgs->optEnabled("--hr")) {
            $hourly = true;
        }

        try {
            if ($query == '') {
                $nick = u($args->nick)->lower();
                // account-level weather.location override (set via PM .set)
                // outranks the nick-keyed row: it stores a free-text query,
                // so it geocodes through the same path an explicit
                // .weather <query> takes; null falls to the row as before
                // units follow the API's si/uk/us setting; the location override does not change units
                $us = $bot->userSystem instanceof UserSystem ? $bot->userSystem : null;
                $override = weather_location_for($us, $args);
                if ($override !== null) {
                    try {
                        $loc = self::getLocation($override)->await();
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
                } else {
                    $location = $entityManager->getRepository(location::class)->findOneBy(["nick" => $nick, "network" => $this->network]);
                    if (!$location) {
                        $bot->msg($args->chan, "You don't have a location set use .setlocation");
                        return;
                    }
                    $si = $location->si;
                }
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

            $exclude = $hourly ? "minutely" : "minutely,hourly";
            $url = "https://api.openweathermap.org/data/3.0/onecall?lat={$location->lat}&lon={$location->long}&appid=$config[openweatherKey]&exclude=$exclude";
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
            if ($hourly) {
                $detailed = $cmdArgs->optEnabled("--detailed") || $cmdArgs->optEnabled("--d");
                $entries = [];
                $cnt = 0;
                foreach ($j['hourly'] as $h) {
                    if ($cnt++ >= 12) break;
                    $entries[] = self::formatHourlyEntry($h, $tz, $si, $detailed);
                }
                $first6 = implode(', ', array_slice($entries, 0, 6));
                $second6 = implode(', ', array_slice($entries, 6, 6));
                $bot->pm($args->chan, "\2{$location->name}:\2 Hourly: $first6");
                if ($second6 != '') {
                    $bot->pm($args->chan, $second6);
                }
            } elseif (!$fc) {
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
    function setlocation(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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