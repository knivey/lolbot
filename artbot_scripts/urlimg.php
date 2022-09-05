<?php
//TODO code syntax highlighting
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

#[Cmd("url", "img")]
#[Syntax('<input>')]
#[CallWrap("Amp\asyncCall")]
#[Options("--rainbow", "--rnb", "--bsize", "--width", '--edit')]
function url($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    $url = $req->args[0] ?? '';
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
        if(!isset($type[0])) {
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
            if($req->args->getOptVal("--width") !== false) {
                $width = intval($req->args->getOptVal("--width"));
                if($width < 10 || $width > 200) {
                    $bot->pm($args->chan, "--width should be between 10 and 200");
                    return;
                }
            }
            $filename_safe = escapeshellarg($filename);
            $thumbnail = `$config[p2u] -f m -p x -w $width $filename_safe`;
            unlink($filename);
            if($req->args->getOpt('--edit')) {
                if(!isset($config['artdir'])) {
                    $bot->pm($args->chan, "artdir not configued");
                    return;
                }
                $artSavePath = "{$config['artdir']}/p2u/";
                if(!is_dir($artSavePath)) {
                    mkdir($artSavePath);
                }
                $name = bin2hex(random_bytes(7)) . '.txt';
                file_put_contents("$artSavePath/$name", $thumbnail);
                $bot->pm($args->chan, "https://asciibird.jewbird.live/?haxAscii=p2u/$name");
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
            if($req->args->getOpt('--rainbow') || $req->args->getOpt('--rnb')) {
                $dir = $req->args->getOptVal('--rainbow');
                if($dir === false)
                    $dir = $req->args->getOptVal('--rnb');
                $dir = intval($dir);
                $bsize = $req->args->getOptVal('--bsize');
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

#[Cmd("ascii")]
#[Syntax("<img_url> [custom_text]...")]
#[CallWrap("Amp\asyncCall")]
#[Options("--width", "--edit", "--block")]
function ascii($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    $url = $req->args[0];
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

        static $palette = [
            [255, 255, 255],
            [0, 0, 0],
            [0, 0, 127],
            [0, 147, 0],
            [255, 0, 0],
            [127, 0, 0],
            [156, 0, 156],
            [252, 127, 0],
            [255, 255, 0],
            [0, 252, 0],
            [0, 147, 147],
            [0, 255, 255],
            [0, 0, 252],
            [255, 0, 255],
            [127, 127, 127],
            [210, 210, 210],
            [0x47, 0x00, 0x00],
            [0x47, 0x21, 0x00],
            [0x47, 0x47, 0x00],
            [0x32, 0x47, 0x00],
            [0x00, 0x47, 0x00],
            [0x00, 0x47, 0x2c],
            [0x00, 0x47, 0x47],
            [0x00, 0x27, 0x47],
            [0x00, 0x00, 0x47],
            [0x2e, 0x00, 0x47],
            [0x47, 0x00, 0x47],
            [0x47, 0x00, 0x2a],
            [0x74, 0x00, 0x00],
            [0x74, 0x3a, 0x00],
            [0x74, 0x74, 0x00],
            [0x51, 0x74, 0x00],
            [0x00, 0x74, 0x00],
            [0x00, 0x74, 0x49],
            [0x00, 0x74, 0x74],
            [0x00, 0x40, 0x74],
            [0x00, 0x00, 0x74],
            [0x4b, 0x00, 0x74],
            [0x74, 0x00, 0x74],
            [0x74, 0x00, 0x45],
            [0xb5, 0x00, 0x00],
            [0xb5, 0x63, 0x00],
            [0xb5, 0xb5, 0x00],
            [0x7d, 0xb5, 0x00],
            [0x00, 0xb5, 0x00],
            [0x00, 0xb5, 0x71],
            [0x00, 0xb5, 0xb5],
            [0x00, 0x63, 0xb5],
            [0x00, 0x00, 0xb5],
            [0x75, 0x00, 0xb5],
            [0xb5, 0x00, 0xb5],
            [0xb5, 0x00, 0x6b],
            [0xff, 0x00, 0x00],
            [0xff, 0x8c, 0x00],
            [0xff, 0xff, 0x00],
            [0xb2, 0xff, 0x00],
            [0x00, 0xff, 0x00],
            [0x00, 0xff, 0xa0],
            [0x00, 0xff, 0xff],
            [0x00, 0x8c, 0xff],
            [0x00, 0x00, 0xff],
            [0xa5, 0x00, 0xff],
            [0xff, 0x00, 0xff],
            [0xff, 0x00, 0x98],
            [0xff, 0x59, 0x59],
            [0xff, 0xb4, 0x59],
            [0xff, 0xff, 0x71],
            [0xcf, 0xff, 0x60],
            [0x6f, 0xff, 0x6f],
            [0x65, 0xff, 0xc9],
            [0x6d, 0xff, 0xff],
            [0x59, 0xb4, 0xff],
            [0x59, 0x59, 0xff],
            [0xc4, 0x59, 0xff],
            [0xff, 0x66, 0xff],
            [0xff, 0x59, 0xbc],
            [0xff, 0x9c, 0x9c],
            [0xff, 0xd3, 0x9c],
            [0xff, 0xff, 0x9c],
            [0xe2, 0xff, 0x9c],
            [0x9c, 0xff, 0x9c],
            [0x9c, 0xff, 0xdb],
            [0x9c, 0xff, 0xff],
            [0x9c, 0xd3, 0xff],
            [0x9c, 0x9c, 0xff],
            [0xdc, 0x9c, 0xff],
            [0xff, 0x9c, 0xff],
            [0xff, 0x94, 0xd3],
            [0x00, 0x00, 0x00],
            [0x13, 0x13, 0x13],
            [0x28, 0x28, 0x28],
            [0x36, 0x36, 0x36],
            [0x4d, 0x4d, 0x4d],
            [0x65, 0x65, 0x65],
            [0x81, 0x81, 0x81],
            [0x9f, 0x9f, 0x9f],
            [0xbc, 0xbc, 0xbc],
            [0xe2, 0xe2, 0xe2],
            [0xff, 0xff, 0xff],
        ];

        $img_string = '';
        $pos = 0;

        $width = 80;
        if($req->args->getOptVal("--width") !== false) {
            $width = intval($req->args->getOptVal("--width"));
            if($width < 10 || $width > 200) {
                $bot->pm($args->chan, "--width should be between 10 and 200");
                return;
            }
        }

        $img = new Imagick();
        $img->readImageBlob($body);
        $size = $img->getImageGeometry();
        $factor = $width / $size['width'];
        $img->scaleImage(round($size['width'] * $factor), round($size['height'] * $factor / 2));

        $size = $img->getImageGeometry();

        $text = $req->args[1];
        if($text != "") {
            $text = strtoupper($text);
            $text = str_replace(' ', '', $text);
            $words = str_split($text);
        }
        if($req->args->getOptVal("--block") !== false) {
            // todo: half block mode
            $words =  ["█"];
        }

        for($row = 0; $row < $size['height']; $row++) {
            $last_match_index = -1;
            for($col = 0; $col < $size['width']; $col++) {
                $pixel = $img->getImagePixelColor($col, $row);
                $rgb = array_values($pixel->getColor());

                $match_index = getClosestMatch($palette, $rgb);

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
                    $str_char = render($pixel->getHSL()['luminosity']);
                    if($match_index != $last_match_index) {
                        $img_string .= "\x03{$match_index}{$str_char}";
                    }
                    else {
                        $img_string .= $str_char;
                    }
                }
                $last_match_index = $match_index;
            }

            $img_string .= "\n";
        }

        $out = [];
        foreach(explode("\n", $img_string) as $line) {
            if($line == '') {
                continue;
            }
            $out[] = $line;
            if($cnt++ > ($config['url_max'] ?? 200)) {
                $out[] = "wow thats a pretty big jones, omitting ~" . count($body)-$cnt . "lines ;-(";
                break;
            }
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

function render($lum) {
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

function getClosestMatch($pallet, $rgb) {
    list($r1, $g1, $b1) = $rgb;
    $matchIndex = 0;
    $dist = 999999;
    foreach ($pallet as $idx => $p) {
        list($r2, $g2, $b2) = $p;
        // don't really need sqrt here since its just comparing?
        $d = ($r2-$r1)**2 + ($g2-$g1)**2 + ($b2-$b1)**2;
        if ($d < $dist) {
            $matchIndex = $idx;
            $dist = $d;
        }
    }
    return $matchIndex;
}

function add($x, $y) {
    return $x + $y;
}

// https://github.com/nalipaz/php-color-difference/blob/master/lib/color_difference.class.php
class color_difference {

    public $color = array();
    public $difference = NULL;

    /**
     * Initialize object
     *
     * @param int $color An integer color, such as a return value from imagecolorat()
     */
    public function __construct($color = array()) {
        if ($color) {
            $this->color = $color;
        }
    }

    public function deltaECIE2000($rgb1, $rgb2) {
        list($l1, $a1, $b1) = $this->_rgb2lab($rgb1);
        list($l2, $a2, $b2) = $this->_rgb2lab($rgb2);

        $avg_lp = ($l1 + $l2) / 2;
        $c1 = sqrt(pow($a1, 2) + pow($b1, 2));
        $c2 = sqrt(pow($a2, 2) + pow($b2, 2));
        $avg_c = ($c1 + $c2) / 2;
        $g = (1 - sqrt(pow($avg_c, 7) / (pow($avg_c, 7) + pow(25, 7)))) / 2;
        $a1p = $a1 * (1 + $g);
        $a2p = $a2 * (1 + $g);
        $c1p = sqrt(pow($a1p, 2) + pow($b1, 2));
        $c2p = sqrt(pow($a2p, 2) + pow($b2, 2));
        $avg_cp = ($c1p + $c2p) / 2;
        $h1p = rad2deg(atan2($b1, $a1p));
        if ($h1p < 0) {
            $h1p += 360;
        }
        $h2p = rad2deg(atan2($b2, $a2p));
        if ($h2p < 0) {
            $h2p += 360;
        }
        $avg_hp = abs($h1p - $h2p) > 180 ? ($h1p + $h2p + 360) / 2 : ($h1p + $h2p) / 2;
        $t = 1 - 0.17 * cos(deg2rad($avg_hp - 30)) + 0.24 * cos(deg2rad(2 * $avg_hp)) + 0.32 * cos(deg2rad(3 * $avg_hp + 6)) - 0.2 * cos(deg2rad(4 * $avg_hp - 63));
        $delta_hp = $h2p - $h1p;
        if (abs($delta_hp) > 180) {
            if ($h2p <= $h1p) {
                $delta_hp += 360;
            }
            else {
                $delta_hp -= 360;
            }
        }
        $delta_lp = $l2 - $l1;
        $delta_cp = $c2p - $c1p;
        $delta_hp = 2 * sqrt($c1p * $c2p) * sin(deg2rad($delta_hp) / 2);

        $s_l = 1 + ((0.015 * pow($avg_lp - 50, 2)) / sqrt(20 + pow($avg_lp - 50, 2)));
        $s_c = 1 + 0.045 * $avg_cp;
        $s_h = 1 + 0.015 * $avg_cp * $t;

        $delta_ro = 30 * exp(-(pow(($avg_hp - 275) / 25, 2)));
        $r_c = 2 * sqrt(pow($avg_cp, 7) / (pow($avg_cp, 7) + pow(25, 7)));
        $r_t = -$r_c * sin(2 * deg2rad($delta_ro));

        $kl = $kc = $kh = 1;

        $delta_e = sqrt(pow($delta_lp / ($s_l * $kl), 2) + pow($delta_cp / ($s_c * $kc), 2) + pow($delta_hp / ($s_h * $kh), 2) + $r_t * ($delta_cp / ($s_c * $kc)) * ($delta_hp / ($s_h * $kh)));

        $this->difference = $delta_e;
        return $delta_e;
    }

    private function _rgb2lab($rgb) {
        return $this->_xyz2lab($this->_rgb2xyz($rgb));
    }

    private function _rgb2xyz($rgb) {
        list($r, $g, $b) = $rgb;

        $r = $r <= 0.04045 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = $g <= 0.04045 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = $b <= 0.04045 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

        $r *= 100;
        $g *= 100;
        $b *= 100;

        $x = $r * 0.412453 + $g * 0.357580 + $b * 0.180423;
        $y = $r * 0.212671 + $g * 0.715160 + $b * 0.072169;
        $z = $r * 0.019334 + $g * 0.119193 + $b * 0.950227;

        return [ $x, $y, $z];
    }

    private function _xyz2lab($xyz) {
        list ($x, $y, $z) = $xyz;

        $x /= 95.047;
        $y /= 100;
        $z /= 108.883;

        $x = $x > 0.008856 ? pow($x, 1 / 3) : $x * 7.787 + 16 / 116;
        $y = $y > 0.008856 ? pow($y, 1 / 3) : $y * 7.787 + 16 / 116;
        $z = $z > 0.008856 ? pow($z, 1 / 3) : $z * 7.787 + 16 / 116;

        $l = $y * 116 - 16;
        $a = ($x - $y) * 500;
        $b = ($y - $z) * 200;

        return [ $l, $a, $b];
    }

    /**
     * Get the closest matching color from the given array of colors
     *
     * @param array $colors array of integers or Color objects
     *
     * @return mixed the array key of the matched color
     */
    public function getClosestMatch(array $colors) {
        $matchDist = 10000;
        $matchKey = null;
        foreach ($colors as $key => $color) {
            $dist = (new color_difference())->deltaECIE2000($this->color, $color);
            if ($dist < $matchDist) {
                $matchDist = $dist;
                $matchKey = $key;
            }
        }

        return $matchKey;
    }

}
