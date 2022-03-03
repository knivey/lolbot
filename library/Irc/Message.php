<?php

namespace Irc;

class Message
{

    public ?string $nick = null;
    public ?string $name = null;
    public ?string $ident = null;
    public ?string $host = null;
    public string $command = '';
    /**
     * @var array<string>
     */
    public array $args = array();

    /**
     * @param string $command
     * @param list<string> $args
     * @param string|null $prefix
     */
    public function __construct(string $command, array $args = array(), ?string $prefix = null)
    {
        $this->command = $command;
        $this->args = $args;

        if (!empty($prefix)) {
            if (str_contains($prefix, '!')) {
                $parts = preg_split('/[!@]/', $prefix);
                $this->nick = $parts[0];
                $this->name = $parts[1] ?? '';
                $this->ident = $this->name;
                $this->host = $parts[2] ?? '';
            } else {
                $this->nick = $prefix;
            }
        }
    }

    public function __toString(): string
    {
        $args = array_map(strval(...), $this->args);
        $len = count($args);
        $last = $len - 1;

        if ($len > 0 && (str_contains($args[$last], ' ') || $args[$last][0] === ':')) {
            $args[$last] = ':' . $args[$last];
        }

        $prefix = $this->getHostString();

        array_unshift($args, $this->command);
        if ($prefix != '')
            array_unshift($args, ":$prefix");

        return implode(' ', $args);
    }

    public function getHostString(): string
    {
        $str = "$this->nick";

        if ($this->name !== null)
            $str .= "!$this->name";

        if ($this->host !== null)
            $str .= "@$this->host";

        return $str;
    }

    public function getIdentHost(): string {
        $str = '';
        if($this->ident != null)
            $str .= "$this->ident@";
        return "{$str}$this->host";
    }

    /**
     * @param int $index
     * @param string|null $defaultValue
     * @return string|null
     *
     * @psalm-template T
     * @psalm-param T $defaultValue
     * @psalm-return T|string
     */
    public function getArg(int $index, ?string $defaultValue = null): ?string
    {
        return $this->args[$index] ?? $defaultValue;
    }

    public static function parse(string $message): ?Message
    {
        if (empty($message))
            return null;

        $args = array();
        $matches = array();

        if (preg_match('/^
            (:(?<prefix>[^ ]+)\s+)?     #the prefix (either "server" or "nick!user@host")
            (?<command>[^ ]+)           #the command (e.g. NOTICE, PRIVMSG)
            (?<args>.*)                 #The argument string
        $/x', $message, $matches)) {

            $prefix = $matches['prefix'] ?? '';
            $command = $matches['command'] ?? '';

            $spacedArg = false;
            if (!empty($matches['args'])) {
                if (str_contains($matches['args'], ' :')) {
                    $parts = explode(' :', $matches['args'], 2);
                    $args = explode(' ', $parts[0]);
                    $spacedArg = $parts[1];
                } else if (str_starts_with($matches['args'], ':'))
                    $spacedArg = substr($matches['args'], 1);
                else
                    $args = explode(' ', $matches['args']);
            }

            $args = array_values(array_filter($args));
            if($spacedArg !== false)
                $args[] = $spacedArg;
        } else
            return new Message('UNKNOWN', array($message));

        return new Message($command, $args, $prefix);
    }
}