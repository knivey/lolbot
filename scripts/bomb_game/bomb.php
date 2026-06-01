<?php
namespace scripts\bomb_game;

use Amp\DeferredFuture;

class bomb {
    /**
     * @param DeferredFuture<int> $def
     */
    public function __construct(
        public string $target,
        public string $color,
        public DeferredFuture $def
    )
    {
    }
}