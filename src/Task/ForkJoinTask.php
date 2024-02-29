<?php

namespace Concurrent\Task;

use Concurrent\{
    FutureInterface,
    ThreadInterface
};
use Concurrent\Lock\{
    LockInterface,
    NotificationInterface
};

class ForkJoinTask implements FutureInterface
{
    public $status;                         // accessed directly by pool and workers
    public const DONE_MASK   = 0xf0000000;  // mask out non-completion bits
    public const NORMAL      = 0xf0000000;  // must be negative
    public const CANCELLED   = 0xc0000000;  // must be < NORMAL
    public const EXCEPTIONAL = 0x80000000;  // must be < CANCELLED
    public const SIGNAL      = 0x00010000;  // must be >= 1 << 16
    public const SMASK       = 0x0000ffff;  // short bits for tags

    //just for testing purposes, to check equality
    private $xid;
    private $notification;

    public function __construct(?NotificationInterface $notification = null)
    {
        $this->xid = uniqid();
        $this->status = new \Swoole\Atomic\Long(0);
        $this->notification = $notification;
    }

    //just for testing purposes, to check equality
    public function equals($obj): bool
    {
        return $this->xid == $obj->xid;
    }

    public function __serialize(): array
    {
        return [
            'xid' => $this->xid,
            'status' => $this->status->get()
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        $this->status = new \Swoole\Atomic\Long(intval($data['status']));
    }

    /**
     * Marks completion and wakes up threads waiting to join this
     * task.
     *
     * @param completion one of NORMAL, CANCELLED, EXCEPTIONAL
     * @return completion status on exit
     */
    private function setCompletion(int $completion): int
    {
        $s = 0;
        while (true) {
            if (($s = $this->status->get()) < 0) {
                return $s;
            }
            if ($s == $this->status->get()) {
                $this->status->set($s | $completion);
                if ($this->uRShift($s, 16) !== 0) {
                    $this->notification->notifyAll(/*$process?*/);
                }
                return $completion;
            }
        }
    }

    private function uRShift(int $a, int $b): int
    {
        if ($b == 0) {
            return $a;
        }
        return ($a >> $b) & ~(1<<(8*PHP_INT_SIZE-1)>>($b-1));
    }

    public function doExec(): void
    {
    }
}
