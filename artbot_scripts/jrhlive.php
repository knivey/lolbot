<?php


use Symfony\Component\Yaml\Yaml;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;

function jrhlive()
{
    global $config;
    if (!isset($config['listen_jrh']) || !isset($config['listen_jrh_path'])) {
        return null;
    }
    //$cert = new Socket\Certificate(__DIR__ . '/../test/server.pem');

    //$context = (new Socket\BindContext)
    //    ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));

    $servers = [
        Socket\Server::listen($config['listen_jrh'])
    ];
    //Probably setup logging from main later
    $logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new HttpServer($servers, new CallableRequestHandler(static function (Request $request) {
        global $config;
        $path = $request->getUri()->getPath();
        if (trim($path, '/') != trim($config['listen_jrh_path'], '/')) {
            return new Response(Status::FORBIDDEN, [
                "content-type" => "text/plain; charset=utf-8"
            ], "Invalid key");
        }

        $chan = "#jrh";
        $body = yield $request->getBody()->buffer();
        try {
            $j = json_decode($body);
        } catch (\Exception $e) {
            echo $e;
            pumpToChan($chan, ["jrh live maybe, but got json errors"]);
            return new Response(Status::OK, [
                "content-type" => "text/plain; charset=utf-8"
            ], "thanks");
        }
$livemsg = trim("
{$j->url}                               https://twitch.tv/hughbord
      😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃 😉 😌 😍 ASCIIBIRD DEVELOPMENT STREAM 😀 😃 😄 😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃
                   WATCH THE WONDERFUL BIRD AND ASCIIBIRD NEARING COMPLETION LIVE NOW
                           ┏   ┰╛    ╔═━┉┈┉╼━━╌┈╍┅┉╌┄┉┉━═╾─┈═──┄┈╼╍═┈┄╍═╍╼━┈─┈╼┉╍┅╌╮
                         ╘███╏████╒█ ┕█   http://jewbird.live/                     ╏
                            █┻█  █┦█  █╕  http://yt.jewbird.live/                  ┇
                          ╔╼█ ████ ████╚━ http://patreon.jewbird.live/             ┃
                         ╕  █ █ █┉╍█ ┌█═  http://streamlabs.jewbird.live/          ╽
                       ━█████ █ ██ █ ╯█   ASCIIBIRD TAKING FLIGHT ASCIIBIRD FLIGHT ╎
                          ┸╮    ╛     ╘╼┈┅┅──━┈┉┅┈╍┄┈┄┈╍┉╾╾╼╍═━╾╾┄╼╾═─┈═┉═╼┅─┈━╌╾╾┅╯
                              [BTC] 1L2u8mQs5pe7k11ozn2BgX388e3fGMD7qo
[XMR] 832owKc3ZuGCnmjHXHeZeeJzGAxyKx5uWU9WxoaXg6BhQ7aWSnZ6EhxFK8Mzw137nSgGAfMM8FgHjM6rpq5s1EofD7UT2yp
           [STREAMLABS] http://streamlabs.jewbird.live [PATREON] http://patreon.jewbird.live
     [YT] http://yt.jewbird.live [TWITCH] http://twitch.jewbird.live [GITHUB] http://git.jewbird.live
😀 😃 😄 😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃 😉 😌 😍 ASCIIBIRD DEVELOPMENT STREAM 😀 😃 😄 😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃
{$j->url}                                https://twitch.tv/hughbord
{$j->title}
");
        pumpToChan($chan, explode("\n", $livemsg));

        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "thanks");

    }), $logger);

    yield $server->start();

    return $server;
}