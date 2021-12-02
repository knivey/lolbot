<?php


namespace scripts\linktitles;


class UrlEvent
{
    public bool $handled = false;
    public string $url = '';
    public $bot;
    public string $chan;
    public string $nick;
    public string $text;

    public array $promises = [];

    public array $replies = [];

    public function addPromise($promise) {
        $this->promises[] = $promise;
    }

    public function reply(string $msg) {
        $this->handled = true;
        $this->replies[] = $msg;
    }

    public function sendReplies($bot, $chan) {
        foreach ($this->replies as $msg)
            $bot->pm($chan, "  $msg");
    }

    public function doLog($bot) {
        logUrl($bot, $this->nick, $this->chan, $this->text, $this->replies);
    }
}