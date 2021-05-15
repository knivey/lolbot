<?php

namespace Irc;

class EventEmitter
{
    protected $eventCallbacks = array();
    protected $onceEventCallbacks = array();

    public function on($event, $callback, &$idx = 0)
    {
        if (strpos($event, ',') !== false) {
            $events = explode(',', $event);
            foreach ($events as $event) {
                $this->on($event, $callback);
            }
            return $this;
        }

        if (empty($this->eventCallbacks[$event]))
            $this->eventCallbacks[$event] = array();

        $this->eventCallbacks[$event][] = $callback;
        $idx = array_key_last($this->eventCallbacks[$event]);
        return $this;
    }

    public function off($event, $callback, $idx = null)
    {
        if (empty($this->eventCallbacks[$event]))
            return $this;

        if($idx == null) {
            foreach ($this->eventCallbacks[$event] as $key => $cb)
                if ($callback === $cb) {
                    $idx = $key;
                    break;
                }
        }

        unset($this->eventCallbacks[$event][$idx]);
        return $this;
    }

    public function once($event, $callback)
    {
        if (empty($this->onceEventCallbacks[$event]))
            $this->onceEventCallbacks[$event] = array();

        $this->onceEventCallbacks[$event][] = $callback;
        return $this;
    }

    public function emit($event, $args = array())
    {
        //if (debug_backtrace()[1]['function'] != 'emit')
        //    echo "EVENT: " . $event . "\n";
        //var_dump($args);
        if (strpos($event, ',') !== false) {
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
