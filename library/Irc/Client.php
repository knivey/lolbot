<?php

namespace Irc;
require_once 'Consts.php';

use Amp\Loop;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\ConnectContext;
use Amp\Socket\ClientTlsContext;
use JetBrains\PhpStorm\Pure;
use Monolog\Logger;
use function Amp\Socket\connect;

function stripForTerminal($str) {
    $str = preg_replace("/(\x1b\[|\x9b)[^@-_]*[@-_]|\x1b[@-_]/", "", $str);
    $str = preg_replace("/[\x1-\x1f]/", "", $str);
    return $str;
}

class Client extends EventEmitter
{
    const DEFAULT_PORT = '6667';

    public bool $exit = false;
    protected string $name = 'phpump';
    protected string $realName = 'we pumpin!';
    protected ?string $serverPassword;
    /**
     * OPTIONNAME => Value
     * Name always uppercased
     * @var array<string, string|array|null>
     */
    protected array $options = array();
    protected bool $reconnect = true;
    protected int $reconnectInterval = 3000;
    protected bool $rawMode = false;
    /**
     * ChannelName => ChannelName
     * @var array<string, string>
     */
    protected array $onChannels = [];

    protected ?ConnectContext $connectContext;
    protected ?EncryptableSocket $socket;
    public bool $isConnected = false;

    protected string $inQ = '';

    protected $lastRecvTime;
    protected ?int $awaitingPong = null;
    protected $timeoutWatcherID = null;
    /**
     * If we have connected and received the welcome thus ready to do any command
     */
    protected bool $ircEstablished = false;

    private ?string $nickHost;

    private int $ErrDelay = 0;

    public function __construct(
        protected string $nick,
        protected string $server,
        public Logger $log,
        protected string $port = self::DEFAULT_PORT,
        protected string $bindIP = '0',
        protected bool $ssl = false)
    {
        //attach basic triggers
        $this->on('message', array($this, 'handleMessage'));

        if ($bindIP != '0') {
            $this->connectContext = (new ConnectContext)->withBindTo($bindIP);
        } else {
            $this->connectContext = (new ConnectContext);
        }
        if ($ssl) {
            $this->connectContext = $this->connectContext->withTlsContext((new ClientTlsContext($server))->withoutPeerVerification());
        }
        $this->log->debug("Bot made", compact('nick'));
    }

    public function exit()
    {
        $this->exit = true;
    }

    public function go()
    {
        \Amp\asyncCall(function() {
            if ($this->exit)
                return;

            $this->log->debug("Bot go called");
            //return;
            while ($this->reconnect && !$this->exit) {
                $this->log->info("connecting...\n");
                try {
                    $this->socket = yield connect($this->server . ':' . $this->port, $this->connectContext);
                    $this->log->info("connected. . .");
                    if ($this->ssl) {
                        $this->log->info("starting tls");
                        yield $this->socket->setupTLS();
                        $this->log->info("tls setup");
                    }
                } catch (\Exception $e) {
                    $this->log->info("connect failed " . $e->getMessage() . " reconnecting in 120 seconds.");
                    yield \Amp\delay(120 * 1000);
                    continue;
                }
                $this->isConnected = true;
                //Yay connected, now login..
                $this->sendLogin();
                while ($this->isConnected) {
                    yield $this->doRead();
                }
                $delay = $this->ErrDelay+10;
                $this->log->info("Reconnecting in $delay seconds...");
                yield \Amp\delay($delay * 1000);
                $this->ErrDelay = 0;
            }
        });
    }

    function doRead()
    {
        return \Amp\call(function () {
            $s = yield $this->socket->read();
            if ($s === null) {
                $this->onDisconnect();
                return;
            }
            $this->lastRecvTime = time();
            if ($this->timeoutWatcherID != null) {
                Loop::cancel($this->timeoutWatcherID);
            }
            $this->timeoutWatcherID = Loop::delay(160000, [$this, 'pingCheck']);
            $this->inQ .= $s;
            if ($this->hasLine()) {
                $this->doLine();
            }
        });
    }

    public function onDisconnect()
    {
        $this->log->info("disconnected");
        $this->sendQ = [];
        if ($this->sendWatcherID != null) {
            Loop::cancel($this->sendWatcherID);
            $this->sendWatcherID = null;
        }
        if ($this->timeoutWatcherID != null) {
            Loop::cancel($this->timeoutWatcherID);
        }
        if(!$this->socket->isClosed())
            $this->socket->close();
        $this->ircEstablished = false;
        $this->isConnected = false;
        $this->awaitingPong = null;
        $this->onChannels = [];
        $this->inQ = '';
        $this->emit('disconnected');
    }

    public function pingCheck()
    {
        $this->timeoutWatcherID = null;
        if ($this->awaitingPong != null) {
            $this->socket->close();
            $this->log->info("Closed connection do to ping timeout.");
            return;
        }
        $this->awaitingPong = time();
        $this->send("PING :" . $this->awaitingPong);
        $this->timeoutWatcherID = Loop::delay(160000, [$this, 'pingCheck']);
    }

    public function sendNow($line): \Amp\Promise
    {
        if (!$this->isConnected)
            return new \Amp\Failure(new Exception("Not connected"));
        $this->log->info(stripForTerminal(">>>> $line"));
        return $this->socket->write($line);
    }

    #[Pure] public function hasLine(): bool
    {
        return (str_contains($this->inQ, "\r") || str_contains($this->inQ, "\n"));
    }

    public function getLine(): ?string
    {
        $r = strpos($this->inQ, "\r");
        $n = strpos($this->inQ, "\n");
        if ($r === false && $n === false) {
            return null;
        }
        $end = (int)max($r, $n) + 1;
        $line = substr($this->inQ, 0, $end);
        $this->inQ = (string)substr($this->inQ, $end);
        return trim($line, "\r\n");
    }

    protected function sendLogin()
    {
        if ($this->rawMode)
            return;

        if ($this->serverPassword !== null)
            $this->send(CMD_PASS, $this->serverPassword);

        if (empty($this->name))
            $this->name = $this->nick;

        if (empty($this->realName))
            $this->realName = $this->name;

        $this->nick();
        $this->send(CMD_USER, $this->name, $this->name, $this->name, $this->realName);
    }

    public function isEstablished(): bool
    {
        return $this->ircEstablished;
    }

    public function getNick(): string
    {
        return $this->nick;
    }

    public function setNick(string $nick): static
    {
        if ($this->isConnected || $this->ircEstablished) {
            //Change nick via NICK command
            //The response of it will change it internally then
            //It's possible that the nick is taken, we try to stay in sync
            $this->nick($nick);
        } else {
            //Else we just change it internally directly
            $this->nick($nick);
            $this->nick = $nick;
        }

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        if (!$this->isConnected) {
            $this->name = $name;
        } else {
            //Set it on the next disconnect
            $this->once('disconnected', function ($e, $c) use ($name) {
                $c->setName($name);
            });
        }

        return $this;
    }

    public function getRealName(): string
    {
        return $this->realName;
    }

    public function setRealName(string $realName): static
    {
        if (!$this->isConnected) {
            $this->realName = $realName;
        } else {
            //Set it on the next disconnect
            $this->once('disconnected', function ($e, $c) use ($realName) {
                $c->setRealName($realName);
            });
        }

        return $this;
    }

    public function getServerPassword(): ?string
    {
        return $this->serverPassword;
    }

    public function setServerPassword(string $password): static
    {
        if (!$this->isConnected) {
            $this->serverPassword = $password;
        }

        return $this;
    }
/* currently not used but if we enabled them it would have two intervals, one for normal and one longer for error
    also a retry amount

    public function getReconnectInterval(): int
    {
        return $this->reconnectInterval;
    }

    public function setReconnectInterval($interval)
    {
        $this->reconnectInterval = $interval;
    }
*/
    public function enableReconnection(): static
    {
        $this->reconnect = true;
        return $this;
    }

    public function disableReconnection(): static
    {
        $this->reconnect = false;
        return $this;
    }

    public function enableRawMode(): static
    {
        $this->rawMode = true;
        return $this;
    }

    public function disableRawMode(): static
    {
        $this->rawMode = false;
        return $this;
    }

    #[Pure] public function getOption(string $option, null|string|array $defaultValue = null): string|array|null
    {
        return ($this->options[strtoupper($option)] ?? $defaultValue);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * some options are arrays
     * check if value is in options array
     * @param string $option
     * @param string $value
     * @return bool
     */
    public function inOptionValues(string $option, string $value): bool
    {
        $oValue = $this->getOption($option, array());
        return in_array($value, $oValue);
    }

    public function inOptionKeys(string $option, string $value): bool
    {
        $oValue = $this->getOption($option, array());
        return in_array($value, array_keys($oValue));
    }

    public function isChannel(string $nick): bool
    {
        return $this->inOptionValues('CHANTYPES', $nick[0]);
    }

    public function isUser(string $nick): bool
    {
        return !$this->isChannel($nick);
    }

    public function doLine(): static
    {
        while ($this->hasLine()) {
            $message = $this->getLine();

            if ($message == '') {
                $this->log->debug("doLine got empty message");
                return $this;
            }

            $msg = Message::parse($message);
            if($msg->command == CMD_PING || $msg->command == CMD_PONG)
                $this->log->debug("IN", ['line' => stripForTerminal("$message")]);
            else
                $this->log->info("IN", ['line' => stripForTerminal("$message")]);
            $this->emit("message, message:$msg->command", array('message' => $msg, 'raw' => $message));
        }
        return $this;
    }

    public function setThrottle(bool $throttle)
    {
        $this->doThrottle = $throttle;
    }

    public float $msg_since = 0;
    public array $sendQ = array();
    public bool $doThrottle = true;
    protected ?string $sendWatcherID = null;

    //Should only be called by watcher
    public function processSendq()
    {
        $this->log->debug("processing sendQ");
        if (empty($this->sendQ)) {
            $this->log->debug("sendQ empty");
            return;
        }

        if ($this->doThrottle == false) {
            while(!empty($this->sendQ)) {
                $msg = array_shift($this->sendQ);
                if(preg_match("/(PING|PONG) .*/i", $msg))
                    $this->log->debug("OUT", ['line' => stripForTerminal($msg)]);
                else
                    $this->log->info("OUT", ['line' => stripForTerminal($msg)]);
                try {
                    yield $this->socket->write($msg);
                } catch (\Exception $e) {
                    $this->log->info("Exception while writing", compact('e'));
                    $this->onDisconnect();
                    return;
                }
            }
            $this->sendWatcherID = null;
            return;
        }

        $time = microtime(true);
        if ($time > $this->msg_since) {
            $this->msg_since = $time;
        }

        foreach ($this->sendQ as $key => $msg) {
            if ($this->msg_since - microtime(true) >= 10) {
                break;
            }
            if(preg_match("/(PING|PONG) .*/i", $msg))
                $this->log->debug("OUT", ['line' => stripForTerminal($msg)]);
            else
                $this->log->info("OUT", ['line' => stripForTerminal($msg)]);
            try {
                yield $this->socket->write($msg);
            } catch (\Exception $e) {
                $this->log->info("Exception while writing", compact('e'));
                $this->onDisconnect();
                return;
            }
            $this->msg_since += 2 + ((strlen($msg) + 2) / 120);
            unset($this->sendQ[$key]);
        }
        $this->sendWatcherID = null;

        if (!empty($this->sendQ)) {
            $next = $this->msg_since - 10 - microtime(true) + 0.1;
            $this->log->debug("delay processingsendq for " . $next * 1000 . "ms");
            $this->sendWatcherID = Loop::delay((int)round($next * 1000), [$this, 'processSendq']);
        }
    }

    /**
     * @param string|Message $command
     * @param string ...$args
     * @return $this
     */
    public function send(string|Message $command, string ...$args): static
    {
        unset($args[0]);
        $args = array_values(array_filter($args, function ($arg) {
            return $arg != '';
        }));

        $message = $command instanceof Message ? $command : new Message($command, $args);

        if (!$this->isConnected) {
            //We can't send anything, when we're not connected.
            //Should we really throw an error or let the user handle it via events?
            return $this;
        }
        //TODO if its a privmsg or notice perhaps line wrap it?
        if ($message->command == 'PRIVMSG' && isset($this->nickHost)) {
            $this->sendQ[] = mb_strcut((string)$message, 0, (508 - strlen(":{$this->nickHost} "))) . "\r\n";
        } else {
            $this->sendQ[] = mb_strcut((string)$message, 0, 510) . "\r\n";
        }

        if ($this->sendWatcherID == null) {
            $this->sendWatcherID = Loop::defer([$this, 'processSendq']);
        }

        $this->emit("sent, sent:$message->command", array('message' => $message));
        return $this;
    }

    /**
     * $channel can be ['#chan','#chan2'] '#channel' or ['#chan'=>'pass', '#chan2'=>'']
     * @param string|array<string> $channel
     * @param string|null $password only used if channel is a string
     * @return $this
     */
    public function join(string|array $channel, ?string $password = null): static
    {
        $channelString = '';
        $passString = '';

        if (is_array($channel)) {
            if (!array_is_list($channel)) {
                //channel => password array
                $channelString = implode(',', array_keys($channel));
                $passString = implode(',', array_values($channel));
            } else {
                $channelString = implode(',', $channel);
            }
        } else {
            $channelString = $channel;
            if ($password !== null)
                $passString = $password;
        }

        $this->send(CMD_JOIN, $channelString, $passString);

        return $this;
    }

    /**
     * @param string|array<string> $channel
     * @return $this
     */
    public function part(string|array $channel): static
    {
        $channel = is_array($channel) ? implode(',', $channel) : $channel;
        $this->send(CMD_PART, $channel);
        return $this;
    }

    /**
     * @param string|array<string>|null $channel
     * @param string|null $server
     * @return $this
     */
    public function names(string|array|null $channel = null, ?string $server = null): static
    {
        $channel = is_array($channel) ? implode(',', $channel) : $channel;
        $this->send(CMD_NAMES, $channel, $server);
        return $this;
    }

    public function onChannel(string $channel): bool {
        return in_array(strtolower($channel), array_map('strtolower', array_keys($this->onChannels)), true);
    }

    /**
     * @return string[]
     */
    public function getJoinedChannels(): array
    {
        return $this->onChannels;
    }

    /**
     * @param string|array<string>|null $channel
     * @param string|null $server
     * @return $this
     */
    public function listChannels(string|array|null $channel = null, ?string $server = null): static
    {
        $channel = is_array($channel) ? implode(',', $channel) : $channel;
        $this->send(CMD_LIST, $channel, $server);
        return $this;
    }

    public function pm(string $nick, string $message): static
    {
        $this->send(CMD_PRIVMSG, $nick, $message);
        return $this;
    }

    public function msg(string $target, string $message): static
    {
        return $this->pm($target, $message);
    }

    public function notice(string $nick, string $message): static
    {
        $this->send(CMD_NOTICE, $nick, $message);
        return $this;
    }

    public function nick(?string $nick = null)
    {
        if ($nick === null)
            $nick = $this->nick;

        $this->send(CMD_NICK, $nick);
    }

    protected function handleMessage($e)
    {
        /* This one handles basic server responses so that the user
           can care about useful functionality instead.

           You can use rawMode to disable automatic interaction in here.
        */

        if ($this->rawMode)
            return;

        /**
         * @var Message|null $message
         */
        $message = $e->message;
        $raw = $e->raw;
        if($message === null)
            return;

        static $namesReply = null,
        $listReply = null;
        switch ($message->command) {
            case "ERROR":
                $this->ErrDelay = 240;
                break;
            case CMD_PING:
                //Reply to pings
                $this->send(CMD_PONG, $message->getArg(0, $this->server));
                break;
            case CMD_PONG:
                if ($this->awaitingPong != null) {
                    $this->awaitingPong = null;
                }
                $this->emit("pong", array(
                    'arg' => $message->getArg(1)
                ));
                break;
            case CMD_JOIN:
                //Emit channel join events
                $nick = $message->nick ?: $this->nick;
                $channel = $message->getArg(0);
                if($nick == $this->getNick()) {
                    $this->onChannels[$channel] = $channel;
                }

                $this->emit("join, join:$channel, join:$nick, join:$channel:$nick", array(
                    'nick' => $nick,
                    'channel' => $channel
                ));
                break;
            case CMD_PART:
                //Emit channel part events
                $nick = $message->nick ?: $this->nick;
                //$channel = $this->addAllChannel( $message->getArg( 0 ) );
                $channel = $message->getArg(0);
                if($nick == $this->getNick()) {
                    unset($this->onChannels[$channel]);
                }

                $this->emit("part, part:$channel, part:$nick, part:$channel:$nick", array(
                    'nick' => $nick,
                    'channel' => $channel
                ));
                break;
            case CMD_KICK:
                //Emit kick events
                $channel = $message->getArg(0);
                $nick = $message->getArg(1);
                if($nick == $this->getNick()) {
                    unset($this->onChannels[$channel]);
                }

                $this->emit("kick, kick:$channel, kick:$nick, kick:$channel:$nick", array(
                    'nick' => $nick,
                    'channel' => $channel
                ));
                break;
            case CMD_NOTICE:
                //Emit notice message events
                $from = $message->nick;
                $to = $message->getArg(0);
                $text = $message->getArg(1, '');

                $this->emit("notice, notice:$to, notice:$to:$from", array(
                    'from' => $from,
                    'nick' => $message->nick,
                    'ident' => $message->name,
                    'host' => $message->host,
                    'identhost' => $message->getIdentHost(),
                    'fullhost' => $message->getHostString(),
                    'to' => $to,
                    'target' => $to,
                    'text' => $text
                ));
                break;
            case CMD_MODE:
                $this->emit("mode", [
                    'from' => $message->nick,
                    'nick' => $message->nick,
                    'ident' => $message->name,
                    'host' => $message->host,
                    'fullhost' => $message->getHostString(),
                    'on' => $message->getArg(0),
                    'args' => array_splice($message->args, 1)
                ]);
                break;
            case CMD_PRIVMSG:
                //Handle private messages (Normal chat messages)
                $from = $message->nick;
                $to = $message->getArg(0);
                $text = $message->getArg(1, '');

                if ($this->isChannel($to)) {
                    $this->emit("chat, chat:$to, chat:$to:$from", array(
                        'from' => $message->nick,
                        'nick' => $message->nick,
                        'ident' => $message->name,
                        'host' => $message->host,
                        'identhost' => $message->getIdentHost(),
                        'fullhost' => $message->getHostString(),
                        'channel' => $to,
                        'target' => $to,
                        'chan' => $to,
                        'text' => $text
                    ));
                    break;
                }

                $this->emit("pm, pm:$to, pm:$to:$from", array(
                    'from' => $message->nick,
                    'nick' => $message->nick,
                    'ident' => $message->name,
                    'host' => $message->host,
                    'fullhost' => $message->getHostString(),
                    'to' => $to,
                    'target' => $to,
                    'text' => $text
                ));
                break;
            case RPL_NAMREPLY:
                if($namesReply != null) {
                    $namesReply->names = array_merge($namesReply->names, explode(' ', $message->getArg(3)));
                } else {
                    $namesReply = (object)array(
                        'nick' => $message->getArg(0),
                        'channelType' => $message->getArg(1),
                        'channel' => $message->getArg(2),
                        'names' => explode(' ', $message->getArg(3))
                    );
                }
                break;
            case RPL_ENDOFNAMES:
                if (empty($namesReply))
                    break;

                $channel = $namesReply->channel;

                $this->emit("names, names:$channel", array(
                    'names' => $namesReply,
                    'channel' => $channel
                ));
                $namesReply = null;
                break;
            case RPL_LISTSTART:
                $listReply = array();
                break;
            case RPL_LIST:
                $channel = $message->getArg(1);
                $listReply[$channel] = (object)array(
                    'channel' => $channel,
                    'userCount' => $message->getArg(2),
                    'topic' => $message->getArg(3)
                );
                break;
            case RPL_LISTEND:
                $this->emit('list', array('list' => $listReply));
                $listReply = null;
                break;
            case RPL_WELCOME:
                //correct internal nickname, if given a new one by the server
                $this->nick = $message->getArg(0, $this->nick);
                $this->ircEstablished = true;
                $this->emit('welcome');
                //$this->send("USERHOST {$this->nick}");
                $this->send(CMD_WHOIS, $this->nick);
                break;
            case RPL_WHOISUSER:
                //var_dump($message);
                if ($message->getArg(1) == $this->nick) {
                    $ident = $message->getArg(2);
                    $host = $message->getArg(3);
                    $this->nickHost = "{$this->nick}!{$ident}@$host";
                }
                break;
            /* this didnt give what i wanted due to servers hiding hostmasks
            case RPL_USERHOST:
                //only worried about ourself ATM
                $rpl = $message->getArg(1);
                if(!preg_match("/([^ =*]+)*?=[+-]([^ ]+)/", $rpl, $m)) {
                    break;
                }
                if(strtolower($m[1]) == strtolower($this->nick)) {
                    $this->nickHost = "{$this->nick}!{$m[2]}";
                }
                break;
            */
            case RPL_ISUPPORT:
                $args = $message->args;
                unset($args[0], $args[count($args)]);

                foreach ($args as $arg) {
                    @list($key, $val) = explode('=', $arg, 2);

                    //handle some keys specifically
                    switch (strtolower($key)) {
                        case 'prefix':

                            list($modes, $prefixes) = explode(')', ltrim($val, '('));
                            $modes = str_split($modes);
                            $prefixes = str_split($prefixes);
                            $val = array();
                            foreach ($modes as $k => $v)
                                $val[$prefixes[$k]] = $v;

                            break;
                        case 'chantypes':
                        case 'statusmsg':
                        case 'elist':

                            $val = str_split($val);
                            break;
                        case 'chanmodes':
                        case 'language':

                            $val = explode(',', $val);
                            break;
                    }

                    $this->options[strtoupper($key)] = $val;
                }

                $this->emit('options', array('options' => $this->options));
                break;
            case ERR_NICKNAMEINUSE:
                $this->setNick(str_shuffle($this->nick));
                break;
                //sometimes connecting to znc or a server will change our nick like so
                //:knivey!~knivy@2001:bc8:182c:a4e::1 NICK :sludg
            case CMD_NICK:
                if($this->getNick() == $message->nick) {
                    $this->nick = $message->getArg(0);
                }
                break;
            default:
                $this->emit($message->command, ['message' => $message]);
        }
    }

}