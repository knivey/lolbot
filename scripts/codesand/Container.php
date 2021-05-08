<?php


namespace codesand;


use Amp\Process\Process;

class Container
{
    public ?string $timeout;
    public bool $busy = false;

    public function __construct(public string $name)
    {
        if($this->getStatus() != "Running") {
            echo "Container $name is not running attempting to start it...\n";
            passthru("lxc start $name");
        }
        if($this->getStatus() != "Running") {
            die("Container $name still is not running?? status: ".$this->getStatus()."\n");
        }
    }

    public function __destruct()
    {
        //Hopefully this helps leave the containers in a clean state
        //But that wont always be the case if bot dies while running something
        if($this->busy) {
            // TODO Cancel any amp watchers etc
            $this->restart();
        }
    }

    /**
     * @return false|mixed Status of container reported by lxc info
     */
    function getStatus() {
        $r = null;
        exec("lxc info {$this->name}", $r);
        foreach($r as $l) {
            if(preg_match("/^Status: (.+)$/i", $l, $m)) {
                return $m[1];
            }
        }
        return false;
    }

    /**
     * Execute command as root on container
     * @param $exec don't send anything that exits quotes
     * @return string output from exec
     */
    function rootExec($exec) {
        echo " {$this->name} root$ $exec\n";
        $r = null;
        exec("lxc exec {$this->name} -- $exec", $r);
        return implode("\n", $r);
    }

    /**
     * Execute command as our user on container
     * @param $exec don't send anything that exits quotes
     * @return string output from exec
     */
    function userExec($exec) {
        echo " {$this->name} user$ $exec\n";
        //$exec = escapeshellarg($exec);
        $r = null;
        exec("lxc exec {$this->name} -- su -l codesand -c \"$exec\"", $r);
        return implode("\n", $r);
    }

    /**
     * Execute command on host
     * @param $exec don't send anything that exits quotes
     * @return string output from exec
     */
    function hostExec($exec) {
        echo " {$this->name} host$ $exec\n";
        $r = null;
        exec($exec, $r);
        return implode("\n", $r);
    }

    function restart() {
        //During testing with forkbombs etc normal kill methods did not work well and took forever
        $this->rootExec("killall -9 -u codesand");
        //restore seems to stop anythign running
        //verboseExec("lxc stop {$this->name}");
        //TODO do this async, runs long time
        $this->hostExec("lxc restore {$this->name} default");
        //server is started after restore, though this could be due to how state was saved
        //verboseExec("lxc start {$this->name}");
    }

    /**
     * Runs PHP code
     * @param string $code
     * @return \Generator
     */
    function runPHP(string $code)
    {
        echo "{$this->name} starting php code run\n";
        $file = __DIR__ . '/code.php';
        $code = "<?php\n$code\n";
        file_put_contents($file, $code);
        $this->hostExec("lxc file push $file codesand/home/codesand/");
        $this->rootExec("chown -R codesand:codesand /home/codesand/");
        yield from $this->runCMD("lxc exec codesand -- su -l codesand -c \"php /home/codesand/code.php ; echo\"");
    }

    /**
     * Runs cmd on container asyncly capturing output for channel
     * @param $cmd
     * @return \Generator
     */
    function runCMD($cmd) {
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
}