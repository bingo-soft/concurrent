<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;
use Concurrent\Worker\InterruptibleProcess;
use Util\Net\{
    InetSocketAddress,
    ServerSocket,
    Socket
};

class LockSupport
{
    private static $port;
    private static $permits;
    private static $state;
    private static $lock;
    private static $initialized = false;
    
    private function __construct()
    {
    }

    //You may run it in a background persistent process to keep socket alive
    public static function init(int $port = 1081): void
    {
        if (self::$initialized === false) {            
            if (self::$port === null) {
                self::$port = new \Swoole\Atomic\Long($port);

                self::$permits = new \Swoole\Table(128);
                self::$permits->column('permit', \Swoole\Table::TYPE_INT, 2);
                self::$permits->create();

                self::$state = new \Swoole\Table(128);
                self::$state->column('type', \Swoole\Table::TYPE_INT, 2);
                self::$state->create();

                self::$lock = new \Swoole\Lock(SWOOLE_MUTEX);
            }

            $socketAddress = new InetSocketAddress("localhost", $port);
            $socket = new Socket();
            $isAlive = false;
            try {
                $socket->connect($socketAddress);
                $socket->close();
                $isAlive = true;
            } catch (\Throwable $t) {
                //ignore
            }
            if ($isAlive === false) {
                $server = new InterruptibleProcess(function ($process) use ($port) {
                    $server = new ServerSocket($port);
                    $waiters = [];
                    $messages = [];
                    try {
                        while ($member = $server->accept()) {
                            $isNotifier = false;
                            while ($message = $member->read(8192)) {
                                //Receive PID of a process to be unparked later
                                if (strpos($message, "h") === false) {
                                    $parts = explode(' ', $message);
                                    foreach ($parts as $part) {
                                        $pair = explode('_', $part);
                                        if (count($pair) === 2) {
                                            $messages[$pair[0]][] = $message;
                                        }
                                    }
                                } else {
                                    //Skip handshake message (h<PID>) from waiting process
                                    //Collect all waiters
                                    $parts = explode(' ', $message);                                   
                                    foreach ($parts as $part) {
                                        $tripple = explode('_', $part);
                                        if (count($tripple) === 3) {
                                            if (isset($waiters[$tripple[1]])) {
                                                try {
                                                    $w = $waiters[$tripple[1]];
                                                    $w->close();
                                                } catch (\Throwable $t) {
                                                    //ignore
                                                }
                                            }
                                            $waiters[$tripple[1]] = $member;
                                            break;
                                        }
                                    }
                                    break;
                                }
                            }                            
                            //Notify all waiters 
                            foreach ($waiters as $key => $waiter) {
                                if (isset($messages[$key])) {
                                    try {
                                        $waiter->write("w");
                                    } catch (\Throwable $t) {
                                        //ignore
                                    }
                                    unset($messages[$key]);
                                }
                            }
                        }
                    } catch (\Throwable $t) {
                        //ignore
                        fwrite(STDERR, getmypid() . ": server socket for IPC is already running or not reachable at port $port\n");
                    }
                });
                $server->start();
            }
            self::$initialized = true;          
        }
    }

    public static function unpark(int $pid): void
    {
        $callTime = hrtime(true);
        $permit = self::$permits->get((string) $pid);
        //$state = $permit !== false && $permit['permit'] === 0;

        self::$permits->set((string) $pid, ['permit' => 1]);

        // Process to be unparked must be indeed blocked
        // but sometimes it takes time for a target process to init socket and send handshake message
        $state = self::$state->get((string) $pid); 
        if (($state !== false && $state['type'] !== 3)) {
            $i = 1;
            //implement backoff strategy
            while (true) {
                usleep(1);
                /*if ($i++ % 10000 === 0) {
                    fwrite(STDERR, getmypid() . ": unparking wait loop takes too much wait cycles [$i]!\n");
                }*/                   
                $state = self::$state->get((string) $pid);                
                if ($state !== false && $state['type'] === 3) {
                    break;
                }                               
            }
        }
        //$client = new Socket("localhost", self::$port->get());
        $client = @stream_socket_client("tcp://localhost:" . self::$port->get());
        /*if (hrtime(true) - $callTime > 1_000_000_000) {
            fwrite(STDERR, getmypid() . ": [WARNING] client socket initializations takes too long: " . floor((hrtime(true) - $callTime) / 1_000_000_000) . " seconds\n");
        }*/
        //$client->write($pid . '_' . $callTime . ' ');
        //$client->close();
        @stream_socket_sendto($client, $pid . '_' . $callTime . ' ');
        @stream_socket_shutdown($client, STREAM_SHUT_WR);
    }

    public static function park(?ThreadInterface $thread = null): void
    {
        self::doPark($thread, 0);
    }

    public static function parkNanos(?ThreadInterface $thread = null, ?int $nanosTimeout = 0): void
    {
        self::doPark($thread, $nanosTimeout);
    }

    private static function doPark(?ThreadInterface $thread = null, ?int $nanosTimeout = 0): void
    {
        $pid = $thread !== null ? $thread->pid : getmypid();

        self::$state->set((string) $pid, ['type' => 0]);

        $callTime = hrtime(true);        
        $permitAvailable = false;

        $currentPermit = intval(self::$permits->get((string) $pid, 'permit'));
        if ($currentPermit > 0) {
            // Mark as available to skip blocking
            self::$permits->set((string) $pid, ['permit' => 0]);
            self::$state->set((string) $pid, ['type' => 0]);         
            $permitAvailable = true;
        }

        if (!$permitAvailable && (($state = self::$state->get((string) $pid)) === false || $state['type'] === 0)) {    
            self::$state->set((string) $pid, ['type' => 2]);
            $client = @stream_socket_client("tcp://localhost:" . self::$port->get());        
            //$client = new Socket('localhost', self::$port->get());
            if (hrtime(true) - $callTime > 1_000_000_000) {
                fwrite(STDERR, getmypid() . ": [WARNING] client socket initializations takes too long: " . floor((hrtime(true) - $callTime) / 1_000_000_000) . " seconds\n");
            }
            @stream_socket_sendto($client, 'h_' . $pid. '_' . $callTime . ' ');
            //$client->write('h_' . $pid. '_' . $callTime . ' ');
            self::$state->set((string) $pid, ['type' => 3]);

            //while ($res = $client->read(8192, PHP_BINARY_READ, $nanosTimeout)) {
            while ($res = self::readSocket($client, 8192, $nanosTimeout)) {
                if (strpos($res, 'w') !== false) {                    
                    self::$permits->del((string) $pid);
                    self::$state->del((string) $pid);
                    @stream_socket_shutdown($client, STREAM_SHUT_WR);
                    //$client->close();            
                    return;
                }
            }
            try {                
                self::$permits->del((string) $pid);
                self::$state->del((string) $pid);
                @stream_socket_shutdown($client, STREAM_SHUT_WR);
                //$client->close();
            } catch (\Throwable $t) {
                fwrite(STDERR, getmypid() . ": client socket closed unexpectadly\n");     
            }
        }
    }

    private static function readSocket($client, $length, $nanos)
    {
        if ($nanos !== 0) {
            $sec = intdiv($nanos, 1000000000);
            $usec = intdiv(($nanos - ($sec * 1000000000)), 1000);

            $read = [$client];
            $write = null;
            $except = null;

            // Use stream_select() to wait for the stream to become available or timeout
            if (false === @stream_select($read, $write, $except, $sec, $usec)) {
                // stream_select() failed
                return false;
            }

            if (!empty($read)) {
                // The stream is ready for reading
                return @stream_socket_recvfrom($client, $length);
            }

            return false;

        } else {
            return @stream_socket_recvfrom($client, $length);
        }
    }
}
