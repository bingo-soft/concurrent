<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;
use Concurrent\Worker\InterruptibleProcess;
use Util\Net\{
    ServerSocket,
    Socket
};

class LockSupport
{
    private static $port;

    private function __construct()
    {
    }

    public static function init(int $port): void
    {
        if (self::$port === null) {
            self::$port = new \Swoole\Atomic\Long($port);
        }

        $server = new InterruptibleProcess(function ($process) use ($port) {
            $server = new ServerSocket($port);
            $waiters = [];
            $pids = [];
            while ($member = $server->accept()) {
                $isNotifier = false;
                while ($pid = $member->read(8192)) {
                    //Receive PID of a process to be unparked
                    if (strpos($pid, "h") === false) {
                        $isNotifier = true;
                        $pids[] = $pid;
                    } else {
                        //Skip handshake message (h<PID>) from waiting process
                        break;
                    }
                }
                //Collect all waiters
                if (!$isNotifier) {
                    $waiters[] = $member;
                }
                //Notify all waiters 
                foreach ($waiters as $waiter) {
                    foreach ($pids as $pid) {
                        $waiter->write($pid);
                    }
                }
            }
        });
        $server->start();
        //$server->wait();
    }

    public static function unpark(int $pid): void
    {
        //@TODO implement permit
        /*//subsequent call does nothing
        if ($thread->permit) {
            return;
        }
        //consume permit
        if (!thread->permit) {
            $thread->permit = true;
        }*/
        $client = new Socket("localhost", self::$port->get());
        $client->write($pid . ' ');
        $client->close();
    }

    public static function park(ThreadInterface $thread): void
    {
        self::doPark($thread);
    }

    public static function parkNanos(ThreadInterface $thread, ?int $nanosTimeout = 0): void
    {
        time_nanosleep(0, $nanos);
        self::doPark($thread);
    }

    private static function doPark(ThreadInterface $thread): void
    {
        /*@TODO implement permit logic
        //only immediately return
        if ($this->interrupted) {
            return;
        }
        //consume permit, immediately return
        if ($this->permit === true) {
            $this->permit = false;
            return;
        }*/
        $client = new Socket('localhost', self::$port->get());
        //Handshake message
        $client->write('h' . $thread->pid. ' ');
        while($res = $client->read(8192)) {
            $notifications = explode(' ', $res);
            foreach ($notifications as $notification) {
                if (is_numeric($notification) && intval($notification) == $thread->pid) {
                    $client->close();
                    break(2);
                }
            }
        }
    }
}
