<?php
//TODO code syntax highlighting
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use Itwmw\ColorDifference\Color;
use Itwmw\ColorDifference\Lib\RGB;

#[Cmd("url", "img")]
#[Syntax('<input>')]
#[CallWrap("Amp\asyncCall")]
#[Options("--rainbow", "--rnb", "--bsize", "--width", '--edit')]
function url($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    global $config;
    $url = $cmdArgs[0] ?? '';
    if(!filter_var($url, FILTER_VALIDATE_URL)) {
        $bot->pm($args->chan, "invalid url");
        return;
    }

    if(preg_match('/^https?:\/\/pastebin.com\/([^\/]+)$/i', $url, $m)) {
        if(strtolower($m[1]) != 'raw') {
            $url = "https://pastebin.com/raw/$m[1]";
        }
    }
    echo "Fetching URL: $url\n";

    try {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);

        /** @var Response $response */
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            $body = substr($body, 0, 200);
            $bot->pm($args->chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }

        $type = explode("/", $response->getHeader('content-type'));
        if($type[0] == '') {
            $bot->pm($args->chan, "content-type not provided");
            return;
        }
        if($type[0] == 'image') {
            if(!isset($config['p2u'])) {
                $bot->pm($args->chan, "p2u hasn't been configued");
                return;
            }
            $ext = $type[1] ?? 'jpg'; // /shrug
            $filename = "url_thumb.$ext";
            echo "saving to $filename\n";
            file_put_contents($filename, $body);
            $width = ($config['url_default_width'] ?? 55);
            if($cmdArgs->optEnabled("--width")) {
                $width = intval($cmdArgs->getOpt("--width"));
                if($width < 10 || $width > 200) {
                    $bot->pm($args->chan, "--width should be between 10 and 200");
                    return;
                }
            }
            $filename_safe = escapeshellarg($filename);
            $thumbnail = `$config[p2u] -f m -p x -w $width $filename_safe`;
            unlink($filename);
            if($cmdArgs->optEnabled('--edit')) {
                if(!isset($config['artdir'])) {
                    $bot->pm($args->chan, "artdir not configured");
                    return;
                }
                $artSavePath = "{$config['artdir']}/p2u/";
                if(!is_dir($artSavePath)) {
                    mkdir($artSavePath);
                }
                $name = bin2hex(random_bytes(7)) . '.txt';
                file_put_contents("$artSavePath/$name", $thumbnail);
                $bot->pm($args->chan, "https://asciibird.birdnest.live/?haxAscii=p2u/$name");
                return;
            }
            $cnt = 0;
            $thumbnail = explode("\n", $thumbnail);
            $out = [];
            foreach ($thumbnail as $line) {
                if($line == '')
                    continue;
                $out[] = $line;
                if($cnt++ > ($config['url_max'] ?? 100)) {
                    $out[] = "wow thats a pretty big image, omitting ~" . count($thumbnail)-$cnt . "lines ;-(";
                    break;
                }
            }
            pumpToChan($args->chan, $out);
        }
        if($type[0] == 'text') {
            var_dump($type);
            if(isset($type[1]) && !preg_match("/^plain;?/", $type[1])) {
                $bot->pm($args->chan, "content-type was ".implode('/', $type)." should be text/plain or image/* (pastebin.com maybe works too)");
                return;
            }
            if($cmdArgs->optEnabled('--rainbow') || $cmdArgs->optEnabled('--rnb')) {
                $dir = $cmdArgs->getOpt('--rainbow');
                if($dir === false)
                    $dir = $cmdArgs->getOpt('--rnb');
                $dir = intval($dir);
                $bsize = $cmdArgs->getOpt('--bsize');
                if(!$bsize)
                    $bsize = null;
                else
                    $bsize = intval($bsize);
                $body = \knivey\irctools\diagRainbow($body, $bsize, $dir);
            }
            $cnt = 0;
            $body = explode("\n", $body);
            $out = [];
            foreach ($body as $line) {
                if($line == '')
                    continue;
                $out[] = $line;
                if($cnt++ > ($config['url_max'] ?? 100)) {
                    $out[] = "wow thats a pretty big text, omitting ~" . count($body)-$cnt . "lines ;-(";
                    break;
                }
            }
            pumpToChan($args->chan, $out);
        }

    } catch (\Amp\MultiReasonException $errors) {
        foreach ($errors->getReasons() as $error) {
            echo $error;
            $bot->pm($args->chan, "\2URL Error:\2 {$error->getMessage()}");
        }
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($args->chan, "\2URL Error:\2 {$error->getMessage()}");
    }
}

static $palette = [
    new Color('#FFFFFF'),
    new Color('#000000'),
    new Color('#00007F'),
    new Color('#009300'),
    new Color('#FF0000'),
    new Color('#7F0000'),
    new Color('#9C009C'),
    new Color('#FC7F00'),
    new Color('#FFFF00'),
    new Color('#00FC00'),
    new Color('#009393'),
    new Color('#00FFFF'),
    new Color('#0000FC'),
    new Color('#FF00FF'),
    new Color('#7F7F7F'),
    new Color('#D2D2D2'),
    new Color('#470000'),
    new Color('#472100'),
    new Color('#474700'),
    new Color('#324700'),
    new Color('#004700'),
    new Color('#00472c'),
    new Color('#004747'),
    new Color('#002747'),
    new Color('#000047'),
    new Color('#2e0047'),
    new Color('#470047'),
    new Color('#47002a'),
    new Color('#740000'),
    new Color('#743a00'),
    new Color('#747400'),
    new Color('#517400'),
    new Color('#007400'),
    new Color('#007449'),
    new Color('#007474'),
    new Color('#004074'),
    new Color('#000074'),
    new Color('#4b0074'),
    new Color('#740074'),
    new Color('#740045'),
    new Color('#b50000'),
    new Color('#b56300'),
    new Color('#b5b500'),
    new Color('#7db500'),
    new Color('#00b500'),
    new Color('#00b571'),
    new Color('#00b5b5'),
    new Color('#0063b5'),
    new Color('#0000b5'),
    new Color('#7500b5'),
    new Color('#b500b5'),
    new Color('#b5006b'),
    new Color('#ff0000'),
    new Color('#ff8c00'),
    new Color('#ffff00'),
    new Color('#b2ff00'),
    new Color('#00ff00'),
    new Color('#00ffa0'),
    new Color('#00ffff'),
    new Color('#008cff'),
    new Color('#0000ff'),
    new Color('#a500ff'),
    new Color('#ff00ff'),
    new Color('#ff0098'),
    new Color('#ff5959'),
    new Color('#ffb459'),
    new Color('#ffff71'),
    new Color('#cfff60'),
    new Color('#6fff6f'),
    new Color('#65ffc9'),
    new Color('#6dffff'),
    new Color('#59b4ff'),
    new Color('#5959ff'),
    new Color('#c459ff'),
    new Color('#ff66ff'),
    new Color('#ff59bc'),
    new Color('#ff9c9c'),
    new Color('#ffd39c'),
    new Color('#ffff9c'),
    new Color('#e2ff9c'),
    new Color('#9cff9c'),
    new Color('#9cffdb'),
    new Color('#9cffff'),
    new Color('#9cd3ff'),
    new Color('#9c9cff'),
    new Color('#dc9cff'),
    new Color('#ff9cff'),
    new Color('#ff94d3'),
    new Color('#000000'),
    new Color('#131313'),
    new Color('#282828'),
    new Color('#363636'),
    new Color('#4d4d4d'),
    new Color('#656565'),
    new Color('#818181'),
    new Color('#9f9f9f'),
    new Color('#bcbcbc'),
    new Color('#e2e2e2'),
    new Color('#ffffff')
];

#[Cmd("ascii")]
#[Syntax("<img_url> [custom_text]...")]
#[CallWrap("Amp\asyncCall")]
#[\knivey\cmdr\attributes\Desc("Generates an ascii from an image url, color matching defaults to Din99")]
#[Option("--width", "how wide to make the ascii ex --width=80")]
#[Option("--edit", "Generate a URL to open the ascii in asciibird editor")]
#[Option("--block", "Render the image with full blocks")]
#[Option("--halfblock", "Render the image with halfblocks")]
#[Option("--quality", "calculate colors using CIEDE2000")]
#[Option("--lab", "calculate colors using euclidian difference in lab colorspace")]
#[Option("--rgb", "calculate colors using euclidian difference in rgb colorspace")]
#[Option("--saturation", "change saturation value as percent, 100 is default")]
#[Option("--brightness", "change brightness value as percent, 100 is default")]
#[Option("--gamma", "adjust the gamma of the image, ex --gamma=0.8")]
#[Option("--render2", "alternate text rending for luminocity")]
#[Option("--16", "limit to only using 16 colors")]
function ascii($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    global $config;
    $url = $cmdArgs[0];
    if(!filter_var($url, FILTER_VALIDATE_URL)) {
        $bot->pm($args->chan, "invalid url");
        return;
    }

    echo "Fetching URL: $url\n";

    try {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);

        /** @var Response $response */
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            $body = substr($body, 0, 200);
            $bot->pm($args->chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }

        $img_string = '';
        $pos = 0;

        if($cmdArgs->optEnabled("--halfblock"))
            $width = 80;
        else
            $width = 120;
        $brightness = 100;
        $saturation = 100;
        $hue = 100;
        $limit = false;
        if($cmdArgs->optEnabled("--16")) {
            $limit = true;
        }
        if($cmdArgs->optEnabled("--width")) {
            $width = intval($cmdArgs->getOpt("--width"));
            if($width < 10 || $width > 200) {
                $bot->pm($args->chan, "--width should be between 10 and 200");
                return;
            }
        }

        $img = new Imagick();
        $img->readImageBlob($body);
        if($cmdArgs->optEnabled("--gamma")) {
            $gamma = $cmdArgs->getOpt("--gamma");

            $img->gammaImage($gamma);
        }
        if($cmdArgs->optEnabled("--saturation")) {
            $saturation = intval($cmdArgs->getOpt("--saturation"));
            if($saturation < 0 || $saturation > 10000) {
                $bot->pm($args->chan, "--saturation should be between 0 and 10000");
                return;
            }
        }
        if($cmdArgs->optEnabled("--brightness")) {
            $brightness = intval($cmdArgs->getOpt("--brightness"));
            if($brightness < 0 || $brightness > 10000) {
                $bot->pm($args->chan, "--brightness should be between 0 and 10000");
                return;
            }
        }
        $img->modulateImage($brightness, $saturation, $hue);
        $size = $img->getImageGeometry();
        $factor = $width / $size['width'];
        $width = $size['width'] * $factor;
        if($cmdArgs->optEnabled("--halfblock"))
            $height = make_even(round($size['height'] * $factor));
        else
            $height = round($size['height'] * $factor / 2);

        $img->resizeImage($width, $height, Imagick::FILTER_LANCZOS2SHARP, 0);
        $size = $img->getImageGeometry();

        $text = $cmdArgs[1];
        if($text != "") {
            $text = strtoupper($text);
            $text = str_replace(' ', '', $text);
            $words = str_split($text);
        }
        if($cmdArgs->optEnabled("--block")) {
            $words =  ["█"];
        }
        pumpToChan($args->chan, ["ok give me a few seconds to generate the ascii.."]);
        //delay so the above actualy has a chance to send first
        yield \Amp\delay(100);

        for($row = 0; $row < $size['height']; $row++) {
            $last_match_index = -1;
            $fg = -1;
            $bg = -1;
            $hb = "▀";
            for($col = 0; $col < $size['width']; $col++) {
                $pixel = $img->getImagePixelColor($col, $row);
                $color = new Color(new RGB(...array_values($pixel->getColor())));
                if($cmdArgs->optEnabled("--halfblock")) {
                    $pixel2 = $img->getImagePixelColor($col, $row + 1);
                    $color2 = new Color(new RGB(...array_values($pixel2->getColor())));
                }


                if($cmdArgs->optEnabled("--quality")) {
                    $match_index = getClosestMatchCIEDE2000($color, $limit);
                } elseif ($cmdArgs->optEnabled("--lab")) {
                    $match_index = getClosestMatchEuclideanLab($color, $limit);
                } elseif ($cmdArgs->optEnabled("--rgb")) {
                    $match_index = getClosestMatchEuclideanRGB($color, $limit);
                } else {
                    $match_index = getClosestMatchDin99($color, $limit);
                }

                if($cmdArgs->optEnabled("--halfblock")) {
                    if($cmdArgs->optEnabled("--quality")) {
                        $match_index2 = getClosestMatchCIEDE2000($color2, $limit);
                    } elseif ($cmdArgs->optEnabled("--lab")) {
                        $match_index2 = getClosestMatchEuclideanLab($color2, $limit);
                    } elseif ($cmdArgs->optEnabled("--rgb")) {
                        $match_index2 = getClosestMatchEuclideanRGB($color2, $limit);
                    } else {
                        $match_index2 = getClosestMatchDin99($color2, $limit);
                    }
                    //just keeping this simple to start with
                    if($match_index != $fg || $match_index2 != $bg) {
                        if($match_index == $match_index2 && $match_index2 == $bg) {
                            $img_string .= " ";
                            continue;
                        }
                        if($bg == $match_index2)
                            $img_string .= "\x03$match_index";
                        else
                            $img_string .= "\x03$match_index,$match_index2";
                        $fg = $match_index;
                        $bg = $match_index2;
                    }
                    if($match_index != $match_index2)
                        $img_string .= $hb;
                    else
                        $img_string .= " ";

                    continue;
                }

                if(isset($words)) {
                    if($match_index != $last_match_index) {
                        $img_string .= "\x03{$match_index}{$words[$pos]}";
                    }
                    else {
                        $img_string .= $words[$pos];
                    }

                    if(++$pos === count($words)) {
                        $pos = 0;
                    }
                }
                else {
                    if($cmdArgs->optEnabled('--render2')) {
                        $str_char = render2($pixel->getHSL()['luminosity']);
                    } else {
                        $str_char = render($pixel->getHSL()['luminosity']);
                    }
                    if($match_index != $last_match_index) {
                        $img_string .= "\x03{$match_index}{$str_char}";
                    }
                    else {
                        $img_string .= $str_char;
                    }
                }
                $last_match_index = $match_index;

            }
            if($cmdArgs->optEnabled("--halfblock"))
                $row++;
            $img_string .= "\n";
        }

        $out = [];
        $cnt = 0;
        foreach(explode("\n", $img_string) as $line) {
            if($line == '') {
                continue;
            }
            $out[] = $line;
            if($cnt++ > ($config['url_max'] ?? 200)) {
                $out[] = "wow thats a pretty big jones, omitting ~" . count(explode("\n", $img_string))-$cnt . "lines ;-(";
                break;
            }
        }

        if($cmdArgs->optEnabled('--edit')) {
            if(!isset($config['artdir'])) {
                $bot->pm($args->chan, "artdir not configured");
                return;
            }
            $artSavePath = "{$config['artdir']}/p2u/";
            if(!is_dir($artSavePath)) {
                mkdir($artSavePath);
            }
            $name = bin2hex(random_bytes(7)) . '.txt';
            file_put_contents("$artSavePath/$name", implode("\n", $out));
            $bot->pm($args->chan, "https://asciibird.birdnest.live/?haxAscii=p2u/$name");
            return;
        }

        pumpToChan($args->chan, $out);

    } catch (\Amp\MultiReasonException $errors) {
        foreach ($errors->getReasons() as $error) {
            echo $error;
            $bot->pm($args->chan, "\2URL Error:\2 {$error->getMessage()}");
        }
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($args->chan, "\2URL Error:\2 {$error->getMessage()}");
    }
}

function make_even($n) {
    return $n - $n % 2;
}

function getClosestMatchCIEDE2000(Color $color, $limit = true) {
    global $palette;
    if($limit)
        $pal = array_slice($palette, 0, 16);
    else
        $pal = $palette;
    $matchIndex = 0;
    $dist = 9999999999999.0;
    foreach ($pal as $idx => $p) {
        $d = $color->getDifferenceCIEDE2000($p);
        if($d < $dist) {
            $matchIndex = $idx;
            $dist = $d;
        }
    }
    return $matchIndex;
}

function getClosestMatchDin99(Color $color, $limit = false) {
    global $palette;
    if($limit)
        $pal = array_slice($palette, 0, 16);
    else
        $pal = $palette;
    $matchIndex = 0;
    $dist = 9999999999999.0;
    foreach ($pal as $idx => $p) {
        $d = $color->getDifferenceDin99($p);
        if($d < $dist) {
            $matchIndex = $idx;
            $dist = $d;
        }
    }
    return $matchIndex;
}

function getClosestMatchEuclideanLab(Color $color, $limit = false) {
    global $palette;
    if($limit)
        $pal = array_slice($palette, 0, 16);
    else
        $pal = $palette;
    $matchIndex = 0;
    $dist = 9999999999999.0;
    foreach ($pal as $idx => $p) {
        $d = $color->getDifferenceEuclideanLab($p);
        if($d < $dist) {
            $matchIndex = $idx;
            $dist = $d;
        }
    }
    return $matchIndex;
}

function getClosestMatchEuclideanRGB(Color $color, $limit = false) {
    global $palette;
    if($limit)
        $pal = array_slice($palette, 0, 16);
    else
        $pal = $palette;
    $matchIndex = 0;
    $dist = 9999999999999.0;
    foreach ($pal as $idx => $p) {
        $d = $color->getDifferenceEuclideanRGB($p);
        if($d < $dist) {
            $matchIndex = $idx;
            $dist = $d;
        }
    }
    return $matchIndex;
}

function render($lum)
{
    $chars = ['.', ':', '-', '~', '+', '*', '=', '>', '%', '$', '&', '#', '@'];
    return $chars[round($lum * (count($chars) -1))];
}

function render2($lum) {
    $chars = [' ','@','8','%','#','*','!','+','=','-',';',':',',','.', '$'];
    $total = $lum * 256;
    switch($total) {
        case $total > 238:
            return $chars[14];
        case $total > 221:
            return $chars[13];
        case $total > 204:
            return $chars[12];
        case $total > 187:
            return $chars[11];
        case $total > 170:
            return $chars[10];
        case $total > 153:
            return $chars[9];
        case $total > 136:
            return $chars[8];
        case $total > 119:
            return $chars[7];
        case $total > 102:
            return $chars[6];
        case $total > 85:
            return $chars[5];
        case $total > 68:
            return $chars[4];
        case $total > 51:
            return $chars[3];
        case $total > 34:
            return $chars[2];
        case $total > 17:
            return $chars[1];
        default:
            return $chars[0];
    }
}
