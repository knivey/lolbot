<?php
namespace scripts\linktitles;

use Amp\Future;

class UrlEvent
{
    public bool $handled = false;
    public string $url = '';
    public string $chan;
    public string $nick;
    public string $text;

    /**
     * @var list<Future>
     */
    public array $futures = [];
    /**
     * @var list<string>
     */
    public array $replies = [];

    /**
     * 
     * @param Future<void> $future 
     * @return void 
     */
    public function addFuture(Future $future) {
        $this->futures[] = $future;
    }

    public function awaitAll() {
        return Future\awaitAll($this->futures);
    }

    public function reply(string $msg) {
        $this->handled = true;
        $this->replies[] = $msg;
    }

    public function sendReplies($bot, $chan) {
        foreach ($this->replies as $msg)
            $bot->pm($chan, "  $msg");
    }

    public function doLog($linktitles, $bot) {
        $linktitles->logUrl($bot, $this->nick, $this->chan, $this->text, $this->replies);
    }
}