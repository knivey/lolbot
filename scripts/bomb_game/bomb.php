<?php
namespace scripts\bomb_game;

use Amp\Deferred;

class bomb {
    public function __construct(
        public string $target,
        public string $color,
        public Deferred $def
    )
    {
    }
}