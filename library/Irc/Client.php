<?php

namespace Irc;
require_once 'Consts.php';

use Amp\CancelledException;
use Amp\Loop;
use Amp\Socket;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\ConnectContext;
use Amp\Socket\ClientTlsContext;
use function Amp\Socket\connect;
use function Amp\asyncCall;

class Client extends EventEmitter {

    const DEFAULT_PORT = 6667;

    public $exit = false;
    protected $nick = 'phpump';
    protected $name = 'phpump';
    protected $realName = 'we pumpin!';
    protected $serverPassword;
    protected $options = array();
    protected $reconnect = true;
    protected $reconnectInterval = 3000;
    protected $rawMode = false;

    protected bool $ssl = false;
    protected ?ConnectContext $connectContext;
    protected ?EncryptableSocket $socket;
    protected $server;
    protected $port;
    protected $isConnected;
    protected $inQ;

    protected $lastRecvTime;
    protected $timeoutWatcherID = null;
    /**
     * If we have connected and recieved the welcome thus ready to do any command
     */
    protected bool $ircEstablished = false;

    public function __construct( $nick, $server, $port = self::DEFAULT_PORT, $bindIP = '0', bool $ssl = false) {
        $this->nick = $nick;
        $this->name = $nick;
        $this->realName = $nick;
        $this->ssl = $ssl;
        $this->server = $server;
        $this->port = $port;

        //attach basic triggers
        $this->on( 'message', array( $this, 'handleMessage' ) );

        //Maybe ned to emit
        //$this->on('disconnected', array($this, 'onDisconnect'));
        if($bindIP != '0') {
            $this->connectContext = (new ConnectContext)->withBindTo($bindIP);
        } else {
            $this->connectContext = (new ConnectContext);
        }
        if($ssl) {
            $this->connectContext = $this->connectContext->withTlsContext((new ClientTlsContext($server))->withoutPeerVerification());
        }
        echo "Bot made";
    }

    public function exit() {
        $this->exit = true;
    }

    public function go() {
        if($this->exit)
            return;

        echo "run called\n";
        //return;
        while($this->reconnect && !$this->exit) {
            echo "connecting...\n";
            try {
                $this->socket = yield connect($this->server . ':' . $this->port, $this->connectContext);
            } catch (\Exception $e) {
                echo "connect failed " . $e->getMessage() . "\n";
                sleep(5); //TODO this is a temoprary fix
                continue;
            }
            echo "connected?\n";
            if ($this->ssl) {
                yield $this->socket->setupTLS();
                echo "tls setup\n";
            }
            $this->isConnected = true;
            //Yay connected, now login..
            $this->sendLogin();
            while($this->isConnected) {
                yield from $this->doRead();
            }
        }
    }

    function doRead() {
        $s = yield $this->socket->read();
        if ($s === null) {
            $this->onDisconnect();
            Loop::cancel($this->timeoutWatcherID);
            return;
        }
        $this->lastRecvTime = time();
        if($this->timeoutWatcherID != null) {
            Loop::cancel($this->timeoutWatcherID);
        }
        $this->timeoutWatcherID = Loop::delay(160000, [$this, 'pingCheck']);
        $this->inQ .= $s;
        if($this->hasLine()) {
            $this->doLine();
        }
    }

    public function pingCheck() {
        $this->timeoutWatcherID = null;
        $this->send("PING");
        //TODO sched another timer to see if we got pong?
    }

    public function sendNow($line) {
        if (!$this->isConnected)
            return;
        echo ">>>> $line";
        yield $this->socket->write($line);
    }

    public function hasLine() {
        if(strpos($this->inQ, "\r") !== false || strpos($this->inQ, "\n") !== false) {
            return true;
        }
        return false;
    }

    public function getLine() : ?string {
        $r = strpos($this->inQ, "\r");
        $n = strpos($this->inQ, "\n");
        if($r === false && $n === false) {
            return null;
        }
        $end = (int)max($r, $n) +1;
        $line = substr($this->inQ, 0, $end);
        //echo "r: $r n: $n end: $end line: $line\ninQ: $this->inQ";
        $this->inQ = (string)substr($this->inQ, $end);
        return trim($line);
    }

    protected function sendLogin() {
        if( $this->rawMode )
            return;

        if( !empty( $this->serverPassword ) )
            $this->send( CMD_PASS, $this->serverPassword );

        if( empty( $this->name ) )
            $this->name = $this->nick;

        if( empty( $this->realName ) )
            $this->realName = $this->name;

        $this->nick();
        $this->send( CMD_USER, $this->name, $this->name, $this->name, $this->realName );
    }

    public function isEstablished() {
        return $this->ircEstablished;
    }

    public function onDisconnect() {
        echo "disconnected\n";
        $this->sendQ = [];
        if($this->sendWatcherID != null) {
            Loop::cancel($this->sendWatcherID);
            $this->sendWatcherID = null;
        }
        //$timer = new Timer([$this, 'connect']);
        //$timer->in(90);
        //EventLoop::addTimer($timer);
        //$this->emit('reconnecting');
        $this->ircEstablished = false;
        $this->isConnected = false;
    }

    public function getNick() {
        return $this->nick;
    }

    public function setNick( $nick ) {
        if( $this->isConnected || $this->ircEstablished ) {
            //Change nick via NICK command
            //The response of it will change it internally then
            //It's possible that the nick is taken, we try to stay in sync
            $this->nick( $nick );
        } else {
            //Else we just change it internally directly
            $this->nick( $nick );
            $this->nick = $nick;
        }

        return $this;
    }

    public function getName() {
        return $this->name;
    }

    public function setName( $name ) {
        if( !$this->isConnected ) {
            $this->name = $name;
        } else {
            //Set it on the next disconnect
            $this->once( 'disconnected', function( $e, $c ) use( $name ) {
                $c->setName( $name );
            } );
        }

        return $this;
    }

    public function getRealName() {
        return $this->realName;
    }

    public function setRealName( $realName ) {
        if( !$this->isConnected ) {
            $this->realName = $realName;
        } else {
            //Set it on the next disconnect
            $this->once( 'disconnected', function( $e, $c ) use( $realName ) {
                $c->setRealName( $realName );
            } );
        }

        return $this;
    }

    public function getServerPassword() {
        return $this->serverPassword;
    }

    public function setServerPassword( $password ) {
        if( !$this->isConnected ) {
            $this->serverPassword = $password;
        }

        return $this;
    }

    public function getReconnectInterval() {
        return $this->reconnectInterval;
    }

    public function setReconnectInterval( $interval ) {
        $this->reconnectInterval = $interval;
    }

    public function setTickInterval( $interval ) {
        $this->tickInterval = $interval;
        return $this;
    }

    public function enableReconnection() {
        $this->reconnect = true;
        return $this;
    }

    public function disableReconnection() {
        $this->reconnect = false;
        return $this;
    }

    public function enableRawMode() {
        $this->rawMode = true;
        return $this;
    }

    public function disableRawMode() {
        $this->rawMode = false;
        return $this;
    }

    public function getOption( $option, $defaultValue = null ) {
        $o = strtoupper( $option );

        if( empty( $this->options[ $o ] ) )
            if( empty( $this->options[ $option ] ) )
                return $defaultValue;
            else
                return $this->options[ $option ];

        return $this->options[ $o ];
    }

    public function getOptions() {
        return $this->options;
    }

    public function inOptionValues( $option, $value ) {
        $oValue = $this->getOption( $option, array() );
        return in_array( $value, $oValue );
    }

    public function inOptionKeys( $option, $value ) {
        $oValue = $this->getOption( $option, array() );
        return in_array( $value, array_keys( $oValue ) );
    }

    public function isChannel( $nick ) {
        return $this->inOptionKeys( 'chantypes', $nick[0] );
    }

    public function isUser( $nick ) {
        return !$this->isChannel( $nick );
    }

    public function doLine() {
        while ($this->hasLine()) {
            $message = $this->getLine();
            echo "<< $message\n";

            if (empty($message))
                return $this;

            $msg = Message::parse($message);
            $this->emit("message, message:$msg->command", array('message' => $msg, 'raw' => $message));
        }
        return $this;
    }

    public function reconnect() {
        return $this->disconnect()
                    ->connect();
    }

    public $msg_since;
    public $sendQ = Array();
    public $doThrottle = true;
    protected $sendWatcherID = null;

    //Should only be called by watcher
    public function processSendq() {
        echo "processing sendQ\n";
        $this->sendWatcherID = null;
        if(empty($this->sendQ)) {
            echo "sendQ empty\n";
            return;
        }

        if($this->doThrottle == false) {
            foreach($this->sendQ as $key => $msg) {
                echo ">> $msg";
                yield $this->socket->write($msg);
                unset($this->sendQ[$key]);
            }
            return;
        }

        $time = microtime(true);
        if($time > $this->msg_since) {
            $this->msg_since = $time;
        }

        foreach($this->sendQ as $key => $msg) {
            if($this->msg_since - microtime(true) >= 10) {
                break;
            }
            echo "> $msg";
            //TODO catch exception?
            yield $this->socket->write($msg);
            $this->msg_since += 2 + ((strlen($msg)+2) / 120);
            unset($this->sendQ[$key]);
        }

        if(!empty($this->sendQ)) {
            $next = $this->msg_since - 10 - microtime(true) + 0.1;
            echo "delay processingsendq for " . $next * 1000 . "ms\n";
            $this->sendWatcherID = Loop::delay($next * 1000, [$this, 'processSendq']);
        }
    }

    public function send( $command ) {
        $args = func_get_args();
        unset( $args[ 0 ] );
        $args = array_values( array_filter( $args, function( $arg ) {
            //$arg = trim( $arg );
            return !empty( $arg );
        } ) );

        $message = $command instanceof Message ? $command : new Message( $command, $args );
        
        if( !$this->isConnected ) {
            //We can't send anything, when we're not connected.
            //Should we really throw an error or let the user handle it via events?
            return $this;
        }
        $this->sendQ[] = (string)$message . "\r\n";
        if($this->sendWatcherID == null) {
            $this->sendWatcherID = Loop::defer([$this, 'processSendq']);
        }

        $this->emit( "sent, sent:$message->command", array( 'message' => $message ) );
        return $this;
    }

    public function sendPrefixed( $prefix, $command ) {
        $args = func_get_args();
        unset( $args[ 0 ] );
        unset( $args[ 1 ] );
        $args = array_values( array_filter( $args, function( $arg ) {
            //$arg = trim( $arg );
            return !empty( $arg );
        } ) );

        $message = new Message( $command, $args, $prefix );

        return $this->send( $message );
    }

    public function join( $channel, $password = null ) {
        $channelString = '';
        $passString = '';

        if( is_array( $channel ) ) {
            if( array_keys( $channel ) !== range( 0, count( $channel ) - 1 ) ) {
                //channel => password array
                $channelString = implode( ',', array_keys( $channel ) );
                $passString = implode( ',', array_values( $channel ) );
            } else {
                $channelString = implode( ',', $channel );
            }
        } else {

            $channelString = $channel;
            if( $password )
                $passString = $password;
        }

        $this->send( CMD_JOIN, $channelString, $passString );

        return $this;
    }

    public function part( $channel ) {
        $channel = is_array( $channel ) ? implode( ',', $channel ) : $channel;
        $this->send( CMD_PART, $channel );
        return $this;
    }

    public function names( $channel = null, $server = null ) {
        $channel = is_array( $channel ) ? implode( ',', $channel ) : $channel;
        $this->send( CMD_NAMES, $channel, $server );
        return $this;
    }

    public function listChannels( $channel = null, $server = null ) {
        $channel = is_array( $channel ) ? implode( ',', $channel ) : $channel;
        $this->send( CMD_LIST, $channel, $server );
        return $this;
    }

    //public function chat( $channel, $message ) {
    //    $this->sendPrefixed( "$this->nick!$this->name", CMD_PRIVMSG, $channel, $message );
    //    return $this;
    //}

    public function pm( $nick, $message ) {
        $this->send(CMD_PRIVMSG, $nick, $message);
        return $this;
    }

    public function nick( $nick = null ) {
        if( !$nick )
            $nick = $this->nick;

        $this->send( CMD_NICK, $nick );
    }

    protected function handleMessage( $e ) {
        /* This one handles basic server reponses so that the user
           can care about useful functionality instead.

           You can use rawMode to disable automatic interaction in here.
        */

        if( $this->rawMode )
            return;

        $message = $e->message;
        $raw = $e->raw;

        static $namesReply = null,
               $listReply = null;
        switch( $message->command ) {
            case CMD_PING:
                //Reply to pings
                $this->send( CMD_PONG, $message->getArg( 0, $this->server ) );
                break;
            case CMD_JOIN:
                //Emit channel join events
                $nick = $message->nick ? $message->nick : $this->nick;
                $channel = $message->getArg( 0 );

                $this->emit( "join, join:$channel, join:$nick, join:$channel:$nick", array( 
                    'nick' => $nick, 
                    'channel' => $channel 
                ) );
                break;
            case CMD_PART:
                //Emit channel part events
                $nick = $message->nick ? $message->nick : $this->nick;
                //$channel = $this->addAllChannel( $message->getArg( 0 ) );
                $channel = $message->getArg( 0 );

                $this->emit( "part, part:$channel, part:$nick, part:$channel:$nick", array( 
                    'nick' => $nick, 
                    'channel' => $channel 
                ) );
                break;
            case CMD_KICK:
                //Emit kick events
                $channel = $message->getArg( 0 );
                $nick = $message->getArg( 1 );

                $this->emit( "kick, kick:$channel, kick:$nick, kick:$channel:$nick", array( 
                    'nick' => $nick, 
                    'channel' => $channel 
                ) );
                break;
            case CMD_NOTICE:
                //Emit notice message events
                $from = $message->nick;
                $to = $message->getArg( 0 );
                $text = $message->getArg( 1, '' );

                $this->emit( "notice, notice:$to, notice:$to:$from", array( 
                    'from' => $from, 
                    'to' => $to, 
                    'text' => $text 
                ) );
                break;
            case CMD_PRIVMSG:
                //Handle private messages (Normal chat messages)
                $from = $message->nick;
                $to = $message->getArg( 0 );
                $text = $message->getArg( 1, '' );

                if( $this->isChannel( $to ) ) {

                    $this->emit( "chat, chat:$to, chat:$to:$from", array( 
                        'from' => $from, 
                        'channel' => $to,
                        'text' => $text
                    ) );
                    break;
                }

                $this->emit( "pm, pm:$to, pm:$to:$from", array( 
                    'from' => $from, 
                    'to' => $to, 
                    'text' => $text 
                ) );
                break;
            case RPL_NAMREPLY:
                $namesReply = (object)array(
                    'nick' => $message->getArg( 0 ),
                    'channelType' => $message->getArg( 1 ),
                    'channel' => $message->getArg( 2 ),
                    'names' => array_map( 'trim', explode( ' ', $message->getArg( 3 ) ) )
                );
            case RPL_ENDOFNAMES:
                if( empty( $namesReply ) )
                    break;

                $channel = $namesReply->channel;

                $this->emit( "names, names:$channel", array( 
                    'names' => $namesReply, 
                    'channel' => $channel 
                ) );
                $namesReply = null;
                break;
            case RPL_LISTSTART:
                $listReply = array();
                break;
            case RPL_LIST:
                $channel = $message->getArg( 1 );
                $listReply[ $channel ] = (object)array(
                    'channel' => $channel,
                    'userCount' => $message->getArg( 2 ),
                    'topic' => $message->getArg( 3 )
                );
                break;
            case RPL_LISTEND:
                $this->emit( 'list', array( 'list' => $listReply ) );
                $listReply = null;
                break;
            case RPL_WELCOME:
                //correct internal nickname, if given a new one by the server
                $this->nick = $message->getArg(0, $this->nick );
                $this->ircEstablished = true;
                $this->emit( 'welcome' );
                break;
            case RPL_ISUPPORT:
                $args = $message->args;
                unset( $args[0], $args[ count( $args ) - 1 ] );

                foreach( $args as $arg ) {
                    @list( $key, $val ) = explode( '=', $arg );

                    //handle some keys specifically
                    switch( strtolower( $key ) ) {
                        case 'prefix':

                            list( $modes, $prefixes ) = explode( ')', ltrim( $val, '(' ) );
                            $modes = str_split( $modes );
                            $prefixes = str_split( $prefixes );
                            $val = array();
                            foreach( $modes as $k => $v )
                                $val[ $prefixes[ $k ] ] = $v;

                            break;
                        case 'chantypes':
                        case 'statusmsg':
                        case 'elist':

                            $val = str_split( $val );
                            break;
                        case 'chanmodes':
                        case 'language':

                            $val = explode( ',', $val );
                            break;
                    }

                    $this->options[ $key ] = $val;
                }

                $this->emit( 'options', array( 'options' => $this->options ) );
                break;
            case ERR_NICKNAMEINUSE:
                $this->setNick(str_shuffle($this->nick));
                break;
        }
    }
    
}