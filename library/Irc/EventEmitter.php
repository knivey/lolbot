<?php

namespace Irc;

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
     * @param string $event
     * @param callable(object $event, EventEmitter $eventEmitter) $callback
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
     * @param string $event
     * @param callable(object $event, EventEmitter $eventEmitter) $callback
     * @param int|null $idx
     * @return $this
     */
    public function off(string $event, callable $callback, ?int $idx = null): static
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
     * @param string $event
     * @param callable(object $event, EventEmitter $eventEmitter) $callback
     * @return $this
     */
    public function once(string $event, callable $callback): static
    {
        $this->onceEventCallbacks[$event][] = $callback;
        return $this;
    }

    //TODO the object sent by this is stupid and dumb would like to make better defined objects

    public function emit(string $event, array $args = array()): static
    {
        if (str_contains($event, ',')) {
            $events = explode(',', $event);
            foreach ($events as $event) {
                $this->emit(trim($event), $args);
            }
            return $this;
        }

        $args['time'] = time();
        $args['event'] = $event;
        $args['sender'] = $this;

        if (!empty($this->onceEventCallbacks[$event])) {
            foreach ($this->onceEventCallbacks[$event] as $callback)
                call_user_func($callback, (object)$args, $this);
            $this->onceEventCallbacks[$event] = array();
        }

        if (!empty($this->eventCallbacks[$event])) {
            foreach ($this->eventCallbacks[$event] as $callback)
                call_user_func($callback, (object)$args, $this);
        }

        return $this;
    }
}
