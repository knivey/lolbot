<?php

namespace codesand;
use Amp\Loop;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

include __DIR__ .'/SafeLineReader.php';

$contList = file_get_contents("container.list", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if(empty($contList)) {
    die("No containers listed");
}

/* @var $containers Container[] */
$containers = [];
foreach($contList as $name) {
    $containers[] = new Container($name);
}

/**
 * Gets next non-busy container or returns false if none
 */
function getContainer() {
    global $containers;
    foreach ($containers as $c) {
        if(!$c->busy)
            return $c;
    }
    return false;
}

$running = null;

class run {
    public $timeout;
    public $chan;
    public $nick;
    public $bot;
    public $code;

    public $out = [];
    public ?Process $proc = null;

    public function __construct($chan, $nick, $bot, $code)
    {
        $this->chan = $chan;
        $this->nick = $nick;
        $this->bot = $bot;
        $this->code = $code;
        //$this->startDisk = getDiskUsed();
    }

    function getStdout() {
        $stream = $this->proc->getStdout();
        $lr = new SafeLineReader($stream);
        $line = '';
        while ($this->timeout != null && null !== $line = yield $lr->readLine()) {
            $this->out[] = "STDOUT: $line";
            if (strlen(implode(' ', $this->out)) > 4000) {
                break;
            }
        }
    }

    function getStderr() {
        $stream = $this->proc->getStderr();
        $lr = new SafeLineReader($stream);
        $line = '';
        while ($this->timeout != null && null !== $line = yield $lr->readLine()) {
            $this->out[] = "STDERR: $line";
            if (strlen(implode(' ', $this->out)) > 4000) {
                break;
            }
        }
    }

    function doPHP()
    {
        echo "starting php code run\n";
        $file = __DIR__ . '/code.php';
        $this->code = "<?php \n" . $this->code . "\n";
        file_put_contents($file, $this->code);
        verboseExec("lxc file push $file codesand/home/codesand/");
        rootExec("chown -R codesand:codesand /home/codesand/");
        //echo userExec("ls -la");
        //echo userExec("cat code.php");
        $this->timeout = \Amp\Loop::delay(3000, [$this, 'timedOut']);

        $cmd = "lxc exec codesand -- su -l codesand -c \"php /home/codesand/code.php ; echo\"";
        echo "launching Process with: $cmd\n";
        $this->proc = new Process($cmd);
        yield $this->proc->start();
        \Amp\asyncCall([$this, 'getStdout']);
        \Amp\asyncCall([$this, 'getStderr']);
        echo "joining proc\n";
        yield $this->proc->join();
        echo "joined proc\n";
        $this->finish();
    }

    public $finished = false;
    function finish() {
        global $running;
        if($this->finished == true) {
            return;
        }
        if($this->timeout != null) {
            \Amp\Loop::cancel($this->timeout);
            $this->timeout = null;
        }
        $this->finished = true;

            restart();

        $this->pushout($this->out);
        $running = null;
    }

    function pushout($buf) {
        $count = 1;
        foreach ($buf as $line) {
            $line = str_replace("\r", '', $line);
            $line = trim($line);
            if($line == 'STDERR:' || $line == 'STDOUT:') {
                continue;
            }
            if(strlen($line) > 400) {
                $line = substr($line, 0, 400) . '...';
            }
            $this->bot->pm($this->chan, $line);
            if(++$count > 10) {
                $this->bot->pm($this->chan, 'Finished. ' . (count($buf) - $count) . " lines omitted...");
                break;
            }
        }
        if($count <= 10) {
            $this->bot->pm($this->chan, 'Finished.');
        }
    }

    function timedOut() {
        $this->bot->pm($this->chan, "Timeout reached");
        $this->timeout = null;
        $this->finish();
    }
}



#[Cmd("php")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPHP($nick, $chan, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config, $running;
    if(!($config['codesand'] ?? false)) {
        return;
    }

    $cont = getContainer();

    if($cont == false) {
        $bot->pm($chan, "$nick, all containers are busy :( try against later.");
        return;
    }

    $output = yield $cont->runPHP($req->args['code']);
    $bot->pm($chan, $output);
}