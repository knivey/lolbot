<?php

namespace codesand;
use Amp\Loop;
use Amp\Process\Process;
use Amp\Process\ProcessException;
include __DIR__ .'/SafeLineReader.php';

$running = null;

class run {
    public $timeout;
    public $chan;
    public $nick;
    public $bot;
    public $code;
    public $startDisk;

    public $out = [];
    public ?Process $proc = null;

    public function __construct($chan, $nick, $bot, $code)
    {
        $this->chan = $chan;
        $this->nick = $nick;
        $this->bot = $bot;
        $this->code = $code;
        $this->startDisk = getDiskUsed();
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
        /*
        if ($this->proc->isRunning()) {
            try {
                $this->proc->kill();
            } catch (\Amp\Process\ProcessException $e) {
                echo "Exception while killing code runner proc ". $e->getMessage() . "\n";
            }
        }
        */

        $used = getDiskUsed();
        if($used != $this->startDisk) {
            echo "Disk used ($used) doesnt match what we started with ($this->startDisk)\nRestarting container from snapshot\n";
            restart();
        } else {
            doReset();
        }
        $this->pushout($this->out);
        $running = null;
    }

    function pushout($buf) {
        $count = 0;
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
            if($count++ > 4) {
                $this->bot->pm($this->chan, "...");
                break;
            }
        }
    }

    function timedOut() {
        $this->bot->pm($this->chan, "Timeout reached");
        $this->timeout = null;
        $this->finish();
    }
}

function verboseExec($exec) {
    echo " host$ $exec\n";
    $r = null;
    exec($exec, $r);
    return implode("\n", $r);
}

function rootExec($exec) {
    echo " root$ $exec\n";
    $r = null;
    exec("lxc exec codesand -- $exec", $r);
    return implode("\n", $r);
}

/**
 * dont send anything that exits quotes
 */
function userExec($exec) {
    echo " user$ $exec\n";
    //$exec = escapeshellarg($exec);
    $r = null;
    exec("lxc exec codesand -- su -l codesand -c \"$exec\"", $r);
    return implode("\n", $r);
}


function getDiskUsed() {
    $used = explode("\n", rootExec("df / --output=used"));
    //var_dump($used);
    return trim($used[1]);
}

function restart() {
    //stop any commands if running

    rootExec("killall -9 -u codesand");
    verboseExec("lxc stop codesand");
    verboseExec("lxc restore codesand default");
    verboseExec("lxc start codesand");
}

function doReset() {
    rootExec("killall -9 -u codesand");
    rootExec("rm -rf /home/codesand");
    rootExec("cp -rT /etc/skel /home/codesand");
    rootExec("chown -R codesand:codesand /home/codesand");
}

//$router->add('php <code>...', __NAMESPACE__ . '\runPHP');
function runPHP($args, $nick, $chan, \Irc\Client $bot) {
    global $user_exec, $root_exec, $running;
    if($running != null) {
        $bot->pm($chan, "Please wait until last task has completed.");
        return;
    }
    $running = new run($chan, $nick, $bot, implode(' ', $args['code']));
    \Amp\asyncCall([$running, 'doPHP']);
}