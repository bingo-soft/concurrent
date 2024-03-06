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
    private static $permits;
    private static $blocks;

    private function __construct()
    {
    }

    public static function init(int $port): void
    {
        if (self::$port === null) {
            self::$port = new \Swoole\Atomic\Long($port);

            self::$permits = new \Swoole\Table(128);
            self::$permits->column('permit', \Swoole\Table::TYPE_INT, 4);
            self::$permits->create();

            self::$blocks = new \Swoole\Table(128);
            self::$blocks->column('blocked', \Swoole\Table::TYPE_INT, 4);
            self::$blocks->create();
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
        $permit = self::$permits->get((string) $pid);
        //if 1, just return, no need to unpark twice
        if ($permit !== false && $permit['permit'] === 1) {
            //fwrite(STDERR, $pid . ": Unpark just returns, because thread was already unparked\n");
            return;
        }
        //consume the permit
        if ($permit === false || $permit['permit'] === 0) {
            self::$permits->set((string) $pid, ['permit' => 1]);
        }

        $block = self::$blocks->get((string) $pid);
        //fwrite(STDERR, $pid . ": Try to unpark, check permit " . json_encode($permit) . ", block " . json_encode($block) . "\n");
        if ($block !== false && $block['blocked'] === 1) {
            //No need to unpark a thread, that was not yet parked
            //fwrite(STDERR, $pid . ": Unpark and send message will take place\n");
            $client = new Socket("localhost", self::$port->get());
            $client->write($pid . ' ');
            $client->close();
        }
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
        /*@TODO
        //only immediately return
        if ($this->interrupted) {
            return;
        }*/
        $permit = self::$permits->get((string) $thread->pid);
        //fwrite(STDERR, $thread->pid . ": Try to park the thread, check permits: " . json_encode($permit) . "\n");
        if ($permit !== false && $permit['permit'] === 1) {
            //fwrite(STDERR, $thread->pid . ": Thread consumed permit and does not block");
            self::$permits->set((string) $thread->pid, ['permit' => 0]);
        } else {
            $block = self::$blocks->get((string) $thread->pid);
            if ($permit === false && ($block === false || $block['blocked'] === 0)) {
                self::$blocks->set((string) $thread->pid, ['blocked' => 1]);
                //fwrite(STDERR, $thread->pid . ": Thread is parked and blocked\n");
                $client = new Socket('localhost', self::$port->get());
                //Handshake message
                $client->write('h' . $thread->pid. ' ');
                //Blocking and waiting a message from unpark call            
                while($res = $client->read(8192)) {
                    $notifications = explode(' ', $res);
                    foreach ($notifications as $notification) {
                        if (is_numeric($notification) && intval($notification) == $thread->pid) {
                            self::$blocks->set((string) $thread->pid, ['blocked' => 0]);
                            $client->close();
                            break(2);
                        }
                    }
                }
            }
        }
    }
}
