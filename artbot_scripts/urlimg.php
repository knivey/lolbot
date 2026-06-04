<?php
//TODO code syntax highlighting
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use draw\IrcPalette;
use draw\Dithering;

#[Cmd("url", "img")]
#[Syntax('<input>')]
#[Options("--rainbow", "--rnb", "--bsize", "--width", '--edit')]
function url(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
    $ctx = \NetworkContext::get($bot);
    $config = $ctx->config;
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
        $response = $client->request($request);
        $body = $response->getBody()->buffer();
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
            $thumbnail = shell_exec("{$config['p2u']} -f m -p x -w $width $filename_safe");
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
            pumpToChan($bot, $args->chan, $out);
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
            pumpToChan($bot, $args->chan, $out);
        }

    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($args->chan, "\2URL Error:\2 {$error->getMessage()}");
    }
}

#[Cmd("ascii")]
#[Syntax("<img_url> [custom_text]...")]
#[\knivey\cmdr\attributes\Desc("Generates an ascii from an image url, color matching defaults to Din99")]
#[Option("--width", "how wide to make the ascii ex --width=80")]
#[Option("--edit", "Generate a URL to open the ascii in asciibird editor")]
#[Option("--block", "Render the image with full blocks")]
#[Option("--halfblock", "Render the image with halfblocks")]
#[Option("--saturation", "change saturation value as percent, 100 is default")]
#[Option("--brightness", "change brightness value as percent, 100 is default")]
#[Option("--gamma", "adjust the gamma of the image, ex --gamma=0.8")]
#[Option("--render2", "alternate text rending for luminocity")]
#[Option("--16", "limit to only using 16 colors")]
#[Option("--no-downsample", "skip 4x Lab downsampling, use direct Imagick resize")]
function ascii(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void {
    $ctx = \NetworkContext::get($bot);
    $config = $ctx->config;
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
        $response = $client->request($request);
        $body = $response->getBody()->buffer();
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
        $limit16 = false;
        if($cmdArgs->optEnabled("--16")) {
            $limit16 = true;
        }

        $img = new Imagick();
        $img->readImageBlob($body);
        if($cmdArgs->optEnabled("--gamma")) {
            $gamma = $cmdArgs->getOpt("--gamma");
            $img->gammaImage($gamma);
        }
        $brightness = 100;
        $saturation = 100;
        $hue = 100;
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
        $origSize = $img->getImageGeometry();
        $factor = $width / $origSize['width'];
        $targetW = (int)round($origSize['width'] * $factor);
        if($cmdArgs->optEnabled("--halfblock"))
            $targetH = (int)make_even(round($origSize['height'] * $factor));
        else
            $targetH = (int)round($origSize['height'] * $factor / 2);

        $noDownsample = $cmdArgs->optEnabled("--no-downsample");

        if ($noDownsample) {
            $img->resizeImage($targetW, $targetH, Imagick::FILTER_LANCZOS2SHARP, 0);
            $size = $img->getImageGeometry();
        } else {
            $midW = $targetW * 4;
            $midH = $targetH * 4;
            $img->resizeImage($midW, $midH, Imagick::FILTER_LANCZOS2SHARP, 0);
            $size = ['width' => $targetW, 'height' => $targetH];
        }

        $text = $cmdArgs[1];
        if($text != "") {
            $text = strtoupper($text);
            $text = str_replace(' ', '', $text);
            $words = str_split($text);
        }
        if($cmdArgs->optEnabled("--block")) {
            $words =  ["█"];
        }
        pumpToChan($bot, $args->chan, ["ok give me a few seconds to generate the ascii.."]);
        //delay so the above actualy has a chance to send first
        Amp\delay(0.05);

        for($row = 0; $row < $size['height']; $row++) {
            $last_match_index = -1;
            $fg = -1;
            $bg = -1;
            $hb = "\u{2580}";
            for($col = 0; $col < $size['width']; $col++) {
                $luminosity = 0.0;

                if ($noDownsample) {
                    $pixel = $img->getImagePixelColor($col, $row);
                    $luminosity = $pixel->getHSL()['luminosity'];
                    $rgba = $pixel->getColor();
                    $match_index = IrcPalette::nearestColor((int)$rgba['r'], (int)$rgba['g'], (int)$rgba['b'], Dithering::None, 0, 0, $limit16);

                    if($cmdArgs->optEnabled("--halfblock")) {
                        $pixel2 = $img->getImagePixelColor($col, $row + 1);
                        $rgba2 = $pixel2->getColor();
                        $match_index2 = IrcPalette::nearestColor((int)$rgba2['r'], (int)$rgba2['g'], (int)$rgba2['b'], Dithering::None, 0, 0, $limit16);
                    }
                } else {
                    $srcX = $col * 4;
                    $srcY = $row * 4;
                    $lSum = 0.0; $aSum = 0.0; $bSum = 0.0; $count = 0;
                    for ($sy = $srcY; $sy < $srcY + 4; $sy++) {
                        for ($sx = $srcX; $sx < $srcX + 4; $sx++) {
                            $px = $img->getImagePixelColor($sx, $sy);
                            $c = $px->getColor();
                            $labColor = new \Itwmw\ColorDifference\Color(new \Itwmw\ColorDifference\Lib\RGB((int)$c['r'], (int)$c['g'], (int)$c['b']));
                            $lab = $labColor->getLab();
                            $lSum += $lab->L;
                            $aSum += $lab->a;
                            $bSum += $lab->b;
                            $count++;
                        }
                    }
                    $match_index = IrcPalette::nearestColorFromLab($lSum / $count, $aSum / $count, $bSum / $count, $limit16);
                    $luminosity = ($lSum / $count) / 100.0;

                    if($cmdArgs->optEnabled("--halfblock")) {
                        $srcY2 = ($row + 1) * 4;
                        $lSum2 = 0.0; $aSum2 = 0.0; $bSum2 = 0.0; $count2 = 0;
                        for ($sy = $srcY2; $sy < $srcY2 + 4; $sy++) {
                            for ($sx = $srcX; $sx < $srcX + 4; $sx++) {
                                $px = $img->getImagePixelColor($sx, $sy);
                                $c = $px->getColor();
                                $labColor = new \Itwmw\ColorDifference\Color(new \Itwmw\ColorDifference\Lib\RGB((int)$c['r'], (int)$c['g'], (int)$c['b']));
                                $lab = $labColor->getLab();
                                $lSum2 += $lab->L;
                                $aSum2 += $lab->a;
                                $bSum2 += $lab->b;
                                $count2++;
                            }
                        }
                        $match_index2 = IrcPalette::nearestColorFromLab($lSum2 / $count2, $aSum2 / $count2, $bSum2 / $count2, $limit16);
                    }
                }

                if($cmdArgs->optEnabled("--halfblock")) {
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
                        $str_char = render2($luminosity);
                    } else {
                        $str_char = render($luminosity);
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

        pumpToChan($bot, $args->chan, $out);
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($args->chan, "\2URL Error:\2 {$error->getMessage()}");
    }
}

function make_even(int|float $n): int|float {
    return $n - $n % 2;
}

function render(float $lum): string
{
    $chars = ['.', ':', '-', '~', '+', '*', '=', '>', '%', '$', '&', '#', '@'];
    $idx = (int)round(max(0, min($lum, 1)) * (count($chars) - 1));
    return $chars[min($idx, count($chars) - 1)];
}

function render2(float $lum): string {
    $chars = [' ','@','8','%','#','*','!','+','=','-',';',':',',','.', '$'];
    $total = max(0, min($lum, 1)) * 256;
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
