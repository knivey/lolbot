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
     * @var list<Future<void>>
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

    /**
     * @return array{list<\Throwable>, list<mixed>}
     */
    public function awaitAll(): array
    {
        return Future\awaitAll($this->futures);
    }

    public function reply(string $msg): void
    {
        $this->handled = true;
        $this->replies[] = $msg;
    }

    public function sendReplies(\Irc\Client $bot, string $chan): void
    {
        foreach ($this->replies as $msg)
            $bot->pm($chan, "  $msg");
    }

    public function doLog(linktitles $linktitles, \Irc\Client $bot): void
    {
        $linktitles->logUrl($bot, $this->nick, $this->chan, $this->text, $this->replies);
    }
}