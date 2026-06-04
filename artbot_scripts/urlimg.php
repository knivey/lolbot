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
#[Option("--16", "limit to only using 16 colors")]
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
        if($cmdArgs->optEnabled("--width")) {
            $width = intval($cmdArgs->getOpt("--width"));
            if($width < 10 || $width > 200) {
                $bot->pm($args->chan, "--width should be between 10 and 200");
                return;
            }
        }
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

        $sampleW = $targetW * 8;
        $sampleH = $targetH * 8;
        $img->resizeImage($sampleW, $sampleH, Imagick::FILTER_LANCZOS, 1);
        $pixels = $img->exportImagePixels(0, 0, $sampleW, $sampleH, "RGB", Imagick::PIXEL_CHAR);

        $lumMap = array_fill(0, $sampleW * $sampleH, 0.0);
        $gxMap = array_fill(0, $sampleW * $sampleH, 0.0);
        $gyMap = array_fill(0, $sampleW * $sampleH, 0.0);
        for ($i = 0; $i < $sampleW * $sampleH; $i++) {
            $lumMap[$i] = 0.299 * $pixels[$i * 3] + 0.587 * $pixels[$i * 3 + 1] + 0.114 * $pixels[$i * 3 + 2];
        }
        for ($y = 1; $y < $sampleH - 1; $y++) {
            for ($x = 1; $x < $sampleW - 1; $x++) {
                $tl = $lumMap[($y - 1) * $sampleW + ($x - 1)];
                $tc = $lumMap[($y - 1) * $sampleW + $x];
                $tr = $lumMap[($y - 1) * $sampleW + ($x + 1)];
                $ml = $lumMap[$y * $sampleW + ($x - 1)];
                $mr = $lumMap[$y * $sampleW + ($x + 1)];
                $bl = $lumMap[($y + 1) * $sampleW + ($x - 1)];
                $bc = $lumMap[($y + 1) * $sampleW + $x];
                $br = $lumMap[($y + 1) * $sampleW + ($x + 1)];
                $idx = $y * $sampleW + $x;
                $gxMap[$idx] = -$tl + $tr - 2 * $ml + 2 * $mr - $bl + $br;
                $gyMap[$idx] = -$tl - 2 * $tc - $tr + $bl + 2 * $bc + $br;
            }
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
        $hb = "\u{2580}";
        $blockSize = 8;

        for($row = 0; $row < $targetH; $row++) {
            $last_match_index = -1;
            $fg = -1;
            $bg = -1;
            $srcY0 = $row * $blockSize;

            $hb_srcY0 = 0;
            if($cmdArgs->optEnabled("--halfblock")) {
                $hb_srcY0 = ($row + 1) * $blockSize;
            }

            for($col = 0; $col < $targetW; $col++) {
                $srcX0 = $col * $blockSize;

                $lSum = 0.0; $aSum = 0.0; $bSum = 0.0;
                for ($sy = $srcY0; $sy < $srcY0 + $blockSize; $sy++) {
                    $rowOff = $sy * $sampleW * 3;
                    for ($sx = $srcX0; $sx < $srcX0 + $blockSize; $sx++) {
                        $idx = $rowOff + $sx * 3;
                        $R = $pixels[$idx] / 255.0;
                        $G = $pixels[$idx + 1] / 255.0;
                        $B = $pixels[$idx + 2] / 255.0;
                        $R = $R > 0.04045 ? (($R + 0.055) / 1.055) ** 2.4 : $R / 12.92;
                        $G = $G > 0.04045 ? (($G + 0.055) / 1.055) ** 2.4 : $G / 12.92;
                        $B = $B > 0.04045 ? (($B + 0.055) / 1.055) ** 2.4 : $B / 12.92;
                        $R *= 100; $G *= 100; $B *= 100;
                        $X = ($R * 0.4124564 + $G * 0.3575761 + $B * 0.1804375) / 95.047;
                        $Y = ($R * 0.2126729 + $G * 0.7151522 + $B * 0.0721750) / 100.0;
                        $Z = ($R * 0.0193339 + $G * 0.1191920 + $B * 0.9503041) / 108.883;
                        $X = $X > 0.008856 ? $X ** (1 / 3) : (7.787 * $X) / (16 / 116);
                        $Y = $Y > 0.008856 ? $Y ** (1 / 3) : (7.787 * $Y) / (16 / 116);
                        $Z = $Z > 0.008856 ? $Z ** (1 / 3) : (7.787 * $Z) / (16 / 116);
                        $lSum += 116 * $Y - 16;
                        $aSum += 500 * ($X - $Y);
                        $bSum += 200 * ($Y - $Z);
                    }
                }
                $blockPixels = $blockSize * $blockSize;
                $avgL = $lSum / $blockPixels;
                $avgA = $aSum / $blockPixels;
                $avgB = $bSum / $blockPixels;
                $match_index = IrcPalette::nearestColorFromLab($avgL, $avgA, $avgB, $limit16);
                $luminosity = $avgL / 100.0;

                if($cmdArgs->optEnabled("--halfblock")) {
                    $lSum2 = 0.0; $aSum2 = 0.0; $bSum2 = 0.0;
                    for ($sy = $hb_srcY0; $sy < $hb_srcY0 + $blockSize; $sy++) {
                        $rowOff = $sy * $sampleW * 3;
                        for ($sx = $srcX0; $sx < $srcX0 + $blockSize; $sx++) {
                            $idx = $rowOff + $sx * 3;
                            $R = $pixels[$idx] / 255.0;
                            $G = $pixels[$idx + 1] / 255.0;
                            $B = $pixels[$idx + 2] / 255.0;
                            $R = $R > 0.04045 ? (($R + 0.055) / 1.055) ** 2.4 : $R / 12.92;
                            $G = $G > 0.04045 ? (($G + 0.055) / 1.055) ** 2.4 : $G / 12.92;
                            $B = $B > 0.04045 ? (($B + 0.055) / 1.055) ** 2.4 : $B / 12.92;
                            $R *= 100; $G *= 100; $B *= 100;
                            $X = ($R * 0.4124564 + $G * 0.3575761 + $B * 0.1804375) / 95.047;
                            $Y = ($R * 0.2126729 + $G * 0.7151522 + $B * 0.0721750) / 100.0;
                            $Z = ($R * 0.0193339 + $G * 0.1191920 + $B * 0.9503041) / 108.883;
                            $X = $X > 0.008856 ? $X ** (1 / 3) : (7.787 * $X) / (16 / 116);
                            $Y = $Y > 0.008856 ? $Y ** (1 / 3) : (7.787 * $Y) / (16 / 116);
                            $Z = $Z > 0.008856 ? $Z ** (1 / 3) : (7.787 * $Z) / (16 / 116);
                            $lSum2 += 116 * $Y - 16;
                            $aSum2 += 500 * ($X - $Y);
                            $bSum2 += 200 * ($Y - $Z);
                        }
                    }
                    $avgL2 = $lSum2 / $blockPixels;
                    $avgA2 = $aSum2 / $blockPixels;
                    $avgB2 = $bSum2 / $blockPixels;
                    $match_index2 = IrcPalette::nearestColorFromLab($avgL2, $avgA2, $avgB2, $limit16);
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
                    $str_char = edgeChar($gxMap, $gyMap, $srcX0, $srcY0, $blockSize, $sampleW, $sampleH) ?? render($luminosity);
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
    $chars = [' ', '.', "'", '`', ':', '-', '~', '+', '=', '!', '*', '?', '/', '^', '#', '%', '$', '&', '@', 'W'];
    $idx = (int)round(max(0, min($lum, 1)) * (count($chars) - 1));
    return $chars[min($idx, count($chars) - 1)];
}

function edgeChar(array $gxMap, array $gyMap, int $srcX0, int $srcY0, int $blockSize, int $sampleW, int $sampleH, float $threshold = 40.0): ?string
{
    $sumGx = 0.0;
    $sumGy = 0.0;
    $yStart = max(1, $srcY0);
    $yEnd = min($sampleH - 1, $srcY0 + $blockSize);
    $xStart = max(1, $srcX0);
    $xEnd = min($sampleW - 1, $srcX0 + $blockSize);
    for ($sy = $yStart; $sy < $yEnd; $sy++) {
        for ($sx = $xStart; $sx < $xEnd; $sx++) {
            $idx = $sy * $sampleW + $sx;
            $sumGx += $gxMap[$idx];
            $sumGy += $gyMap[$idx];
        }
    }
    $pixels = ($yEnd - $yStart) * ($xEnd - $xStart);
    if ($pixels == 0) {
        return null;
    }
    $mag = sqrt($sumGx * $sumGx + $sumGy * $sumGy) / $pixels;
    if ($mag <= $threshold) {
        return null;
    }
    $angle = atan2($sumGy, $sumGx) * 180.0 / M_PI;
    if ($angle < 0) {
        $angle += 180.0;
    }
    if ($angle < 22.5 || $angle >= 157.5) {
        return '|';
    }
    if ($angle < 67.5) {
        return '/';
    }
    if ($angle < 112.5) {
        return '=';
    }
    return '\\';
}
