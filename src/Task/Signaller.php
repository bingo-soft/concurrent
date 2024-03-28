<?php

namespace Concurrent\Task;

use Concurrent\ThreadInterface;
use Concurrent\Executor\ManagedBlockerInterface;
use Concurrent\Lock\LockSupport;

class Signaller extends Completion implements ManagedBlockerInterface
{
    public $nanos;              // remaining wait time if timed
    public $deadline;           // non-zero if timed
    public $interruptible = false;
    public $interrupted = false;

    public function __construct(bool $interruptible, int $nanos, int $deadline)
    {
        parent::__construct();
        $this->interruptible = $interruptible;
        $this->nanos = $nanos;
        $this->deadline = $deadline;
        CompletableFuture::$signallers->set($this->xid, ['pid' => getmypid()]);
    }

    /*public function __destruct()
    {
        CompletableFuture::$signallers->del($this->xid);
    }*/

    public function __serialize(): array
    {
        return [
            'xid' => $this->xid,
            'interruptible' => $this->interruptible,
            'interrupted' => $this->interrupted,
            'nanos' => $this->nanos,
            'deadline' => $this->deadline
        ];
    }

    public function __unserialize(array $data): void
    {        
        $this->xid = $data['xid'];
        $this->interruptible = $data['interruptible'];
        $this->interrupted = $data['interrupted'];
        $this->nanos = $data['nanos'];
        $this->deadline = $data['deadline'];
    }

    public function tryFire(int $ignore): ?CompletableFuture
    {
        if (CompletableFuture::$signallers->get($this->xid) !== false) {
            $pid = CompletableFuture::$signallers->get($this->xid, 'pid');
            CompletableFuture::$signallers->del($this->xid);
            LockSupport::unpark($pid);                       
        }
        return null;
    }

    public function isReleasable(): bool
    {
        //if (Thread.interrupted())
        //    interrupted = true;
        $check = (($this->interruptible) ||
                ($this->deadline != 0 &&
                    ($this->nanos <= 0 ||
                    ($this->nanos = $this->deadline - hrtime(true)) <= 0)) ||
                    CompletableFuture::$signallers->get($this->xid) === false);
        return $check;
    }

    public function block(): bool
    {
        while (!$this->isReleasable()) {
            if ($this->deadline == 0) {
                LockSupport::park(null);
            } else {
                LockSupport::parkNanos(null, $this->nanos);
            }
        }
        return true;
    }

    public function isLive(): bool
    {
        return $this->pid !== null;
    }
}
