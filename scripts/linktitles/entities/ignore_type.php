<?php

namespace scripts\linktitles\entities;

enum ignore_type: int
{
    case global = 1;
    case network = 2;
    case bot = 3;
    case channel = 4;

    static public function fromString(string $string): self
    {
        return match(strtolower($string)) {
            "global" => self::global,
            "network" => self::network,
            "bot" => self::bot,
            "channel", "chan" => self::channel
        };
    }

    public function toString(): string
    {
        return match($this) {
            self::global => "global",
            self::network => "network",
            self::bot => "bot",
            self::channel => "channel"
        };
    }
}