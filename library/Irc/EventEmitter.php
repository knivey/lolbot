<?php

namespace Irc;

use Irc\Event\Event;

/**
 * @package Irc
 */
class EventEmitter
{
    /**
     * @var array<string, list<callable>>
     */
    protected array $eventCallbacks = array();
    /**
     * @var array<string, list<callable>>
     */
    protected array $onceEventCallbacks = array();

    /**
     * @param callable $callback
     * @param int|null $idx
     * @return $this
     */
    public function on(string $event, callable $callback, ?int &$idx = null): static
    {
        if (str_contains($event, ',')) {
            $events = explode(',', $event);
            foreach ($events as $event) {
                $this->on($event, $callback);
            }
            return $this;
        }

        $this->eventCallbacks[$event][] = $callback;
        $idx = array_key_last($this->eventCallbacks[$event]);
        return $this;
    }

    /**
     * @param callable|null $callback
     * @param int|null $idx
     * @return $this
     */
    public function off(string $event, ?callable $callback, ?int $idx = null): static
    {
        if (!isset($this->eventCallbacks[$event]) || empty($this->eventCallbacks[$event]) )
            return $this;

        if($idx === null) {
            foreach ($this->eventCallbacks[$event] as $key => $cb)
                if ($callback === $cb) {
                    $idx = $key;
                    break;
                }
        }
        if($idx === null)
            return $this;

        unset($this->eventCallbacks[$event][$idx]);
        return $this;
    }

    /**
     * @param callable $callback
     * @return $this
     */
    public function once(string $event, callable $callback): static
    {
        $this->onceEventCallbacks[$event][] = $callback;
        return $this;
    }

    /**
     * @param Event|null $args
     */
    public function emit(string $event, ?Event $args = null): static
    {
        if (str_contains($event, ',')) {
            $events = explode(',', $event);
            foreach ($events as $ev) {
                $this->emit(trim($ev), $args);
            }
            return $this;
        }

        if ($args === null) {
            $args = new class(time(), $event, $this) extends Event {};
        }
        $args->event = $event;

        if (!empty($this->onceEventCallbacks[$event])) {
            foreach ($this->onceEventCallbacks[$event] as $callback)
                call_user_func($callback, $args, $this);
            $this->onceEventCallbacks[$event] = array();
        }

        if (!empty($this->eventCallbacks[$event])) {
            foreach ($this->eventCallbacks[$event] as $callback)
                call_user_func($callback, $args, $this);
        }

        return $this;
    }
}
