<?php

use Amp\Future;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Form;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use knivey\cmdr\attributes\Option;

#[Cmd("yoda")]
#[Syntax('<url>...')]
#[Option("--og", "OG Yoda")]
function yoda_cmd($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $url = $cmdArgs['url'];

    if(!filter_var($url, FILTER_VALIDATE_URL))
        return;

    try {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);

        /** @var Response $response */
        $response = $client->request($request);
        $body = $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            $bot->pm($args->chan, "Server returned {$response->getStatus()}");
            return;
        }

        $img = new Imagick();
        try {
            $img->readImageBlob($body);
            if(!$img->getImageFormat()) {
                throw new \Exception();
            }
        } catch (\Exception $e) {
            $bot->pm($args->chan, "couldn't recognize any image data");
            return;
        }

        $yodaImg = new Imagick();
        $yodaImgFile = $cmdArgs->optEnabled('--og') ? "/yoda-og.png" : "/yoda.png";
        $yodaImg->readImage(__DIR__ . $yodaImgFile);
        $yodaImg->scaleImage($img->getImageWidth(), $img->getImageHeight());

        // original behavior
        if ($cmdArgs->optEnabled('--og')) {
            $newWidth = $yodaImg->getImageWidth() + $img->getImageWidth();
            $newHeight = max($yodaImg->getImageHeight(), $img->getImageHeight());
            $finalImg = new Imagick();
            $finalImg->newImage($newWidth, $newHeight, new ImagickPixel('transparent'));
            $finalImg->compositeImage($yodaImg, Imagick::COMPOSITE_OVER, 0, 0);
            $finalImg->compositeImage($img, Imagick::COMPOSITE_OVER, $yodaImg->getImageWidth(), 0);
            $img = $finalImg;
        } else {
            // new chunky behavior
            $img->compositeImage($yodaImg, Imagick::COMPOSITE_BLEND, 0, 0);
        }

        $tmpfile = tempnam(sys_get_temp_dir(), 'yoda') . '.webp';
        $img->writeImage($tmpfile);
        $yodaPic = hostToFilehole($tmpfile)->await();
        unlink($tmpfile);
        $bot->pm($args->chan,  $yodaPic);
    } catch (\Exception $e) {
        return;
    }
}

#[Cmd("doubleyoda")]
#[Syntax('<url>...')]
function doubleyoda_cmd($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $url = $cmdArgs['url'];

    if(!filter_var($url, FILTER_VALIDATE_URL))
        return;

    try {
        $body = async_get_contents($url);
        $img = new Imagick();
        $img->readImageBlob($body);
        if(!$img->getImageFormat()) {
            throw new \Exception("data recieved not recognized as image");
        }
        $yoda = new Imagick(__DIR__ . "/yoda-og.png");
        //$yoda->scaleImage(0, $img->getImageHeight());
        $final = new Imagick();
        $width = $img->getImageWidth() + $yoda->getImageWidth() * 2;
        $height = max($img->getImageHeight(), $yoda->getImageHeight());
        $final->newImage($width, $height, new ImagickPixel('transparent'));
        $final->compositeImage($yoda, Imagick::COMPOSITE_OVER, 0, 0);
        $final->compositeImage($img, Imagick::COMPOSITE_OVER, $yoda->getImageWidth(), 0);
        $yoda->flopImage();
        $final->compositeImage($yoda, Imagick::COMPOSITE_OVER, $width - $yoda->getImageWidth(), 0);
        $tmpfile = tempnam(sys_get_temp_dir(), 'yoda') . '.webp';
        $final->writeImage($tmpfile);
        $yodaPic = hostToFilehole($tmpfile)->await();
        unlink($tmpfile);
        $bot->pm($args->chan,  $yodaPic);
    } catch (\Exception $e) {
        $bot->pm($args->chan,  "yoda troubles: {$e->getMessage()}");
        return;
    }
}

/**
 * 
 * @param string $filename 
 * @return Future<string> 
 */
function hostToFilehole(string $filename): Future
{
    return \Amp\async(function () use ($filename) {
        if(!file_exists($filename))
            throw new \Exception("hostToFilehole called with non existant filename: $filename");
        $client = HttpClientBuilder::buildDefault();
        $request = new Request("https://upload.beer", "POST");
        $body = new Form();
        $body->addField('url_len', '5');
        $body->addField('expiry', '86400');
        $body->addFile('file', $filename);
        $request->setBody($body);
        //var_dump($request);
        /** @var Response $response */
        $response = $client->request($request);
        //var_dump($response);
        if ($response->getStatus() != 200) {
            throw new \Exception("upload.beer returned {$response->getStatus()}");
        }
        $respBody = $response->getBody()->buffer();
        return $respBody;
    });
}
