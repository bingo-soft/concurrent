<?php

namespace Concurrent\Queue;

use Concurrent\{
    RunnableScheduledFutureInterface,
    ThreadInterface,
    TimeUnit
};
use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\Lock\{
    LockSupport,
    ReentrantLock
};
use Concurrent\Task\ScheduledFutureTask;

class DelayedWorkQueue extends AbstractQueue implements BlockingQueueInterface
{
    /*
     * A DelayedWorkQueue is based on a heap-based data structure
     * like those in DelayQueue and PriorityQueue, except that
     * every ScheduledFutureTask also records its index into the
     * heap array. This eliminates the need to find a task upon
     * cancellation, greatly speeding up removal (down from O(n)
     * to O(log n)), and reducing garbage retention that would
     * otherwise occur by waiting for the element to rise to top
     * before clearing. But because the queue may also hold
     * RunnableScheduledFutures that are not ScheduledFutureTasks,
     * we are not guaranteed to have such indices available, in
     * which case we fall back to linear search. (We expect that
     * most tasks will not be decorated, and that the faster cases
     * will be much more common.)
     *
     * All heap operations must record index changes -- mainly
     * within siftUp and siftDown. Upon removal, a task's
     * heapIndex is set to -1. Note that ScheduledFutureTasks can
     * appear at most once in the queue (this need not be true for
     * other kinds of tasks or work queues), so are uniquely
     * identified by heapIndex.
     */

    public $queue;

    public $lock;
    //public $swooleLock;
    public $size;

    /**
     * Thread designated to wait for the task at the head of the
     * queue.  This variant of the Leader-Follower pattern
     * (http://www.cs.wustl.edu/~schmidt/POSA/POSA2/) serves to
     * minimize unnecessary timed waiting.  When a thread becomes
     * the leader, it waits only for the next delay to elapse, but
     * other threads await indefinitely.  The leader thread must
     * signal some other thread before returning from take() or
     * poll(...), unless some other thread becomes leader in the
     * interim.  Whenever the head of the queue is replaced with a
     * task with an earlier expiration time, the leader field is
     * invalidated by being reset to null, and some waiting
     * thread, but not necessarily the current leader, is
     * signalled.  So waiting threads must be prepared to acquire
     * and lose leadership while waiting.
     */
    public $leader;

    /**
     * Condition signalled when a newer task becomes available at the
     * head of the queue or a new thread may need to become leader.
     */
    public $available;

    //8KB per serialized task, should be enough
    public const DEFAULT_SIZE = 8192;

    //Maximum number of active tasks
    public const DEFAULT_MAX_TASKS = 2056;

    public function __construct(?int $maxTasks = self::DEFAULT_MAX_TASKS, ?int $maxTaskSize = self::DEFAULT_SIZE, ?int $notificationPort = 1081)
    {       
        //$this->swooleLock = new \Swoole\Lock(SWOOLE_MUTEX);
        $this->lock = new ReentrantLock(true);
        $this->available = $this->lock->newCondition();
        $this->queue  = new \Swoole\Table($maxTasks);
        $this->queue->column('xid', \Swoole\Table::TYPE_STRING, 32); 
        $this->queue->column('task', \Swoole\Table::TYPE_STRING, $maxTaskSize);                 
        $this->queue->create();

        $this->size = new \Swoole\Atomic\Long(0);
        $this->leader = new \Swoole\Atomic\Long(-1);
        
        LockSupport::init($notificationPort);
    }

    /**
     * Sets f's heapIndex if it is a ScheduledFutureTask.
     */
    private static function setIndex(RunnableScheduledFutureInterface | string $f, int $idx): void
    {
        if (is_string($f)) {
            $f = unserialize($f);
        }
        if ($f instanceof ScheduledFutureTask) {
            ScheduledFutureTask::$heapIndices->set($f->getXid(), ['xid' => $f->getXid(), 'index' => $idx]);
        }
    }

    /**
     * Sifts element added at bottom up to its heap-ordered spot.
     * Call only when holding lock.
     */
    private function siftUp(int $k, RunnableScheduledFutureInterface $key): void
    {
        $it = 0;
        $break = 0;
        while ($k > 0) {
            $it += 1;
            $parent = $this->uRShift($k - 1, 1);
            $e = $this->queue->get($parent, 'task');
            $eu = unserialize($e);
            if ($key->compareTo($eu) >= 0) {
                $break = 1;
                break;
            }
            $this->queue->set($k, ['xid' => $eu->getXid(), 'task' => $e]);
            $this->setIndex($e, $k);          
            $k = $parent;
        }
        $this->queue->set($k, ['xid' => $key->getXid(), 'task' => serialize($key)]);        

        $this->setIndex($key, $k);
    }

    private function uRShift(int $a, int $b): int
    {
        if ($b == 0) {
            return $a;
        }
        return ($a >> $b) & ~(1<<(8*PHP_INT_SIZE-1)>>($b-1));
    }

    /**
     * Sifts element added at top down to its heap-ordered spot.
     * Call only when holding lock.
     */
    private function siftDown(int $k, RunnableScheduledFutureInterface $key): void
    {
        $half = $this->uRShift($this->size->get(), 1);
        while ($k < $half) {
            $child = ($k << 1) + 1;            
            $c = $this->queue->get((string) $child, 'task');
            $cu = unserialize($c);
            $right = $child + 1;
            if ($right < $this->size->get() && $cu->compareTo($cn = unserialize($cc = $this->queue->get((string) $right, 'task'))) > 0) {
                $child = $right;
                $cu = $cn;
                $c = $cc;
            }
            if ($key->compareTo($cu) <= 0) {
                break;
            }
            $this->queue->set((string) $k, ['xid' => $cu->getXid(), 'task' => $c]);
            $this->setIndex($c, $k);
            $k = $child;
        }
        $this->queue->set((string) $k, ['xid' => $key->getXid(), 'task' => serialize($key)]);
        $this->setIndex($key, $k);
        
    }

    /**
     * Finds index of given object, or -1 if absent.
     */
    private function indexOf($x = null): int
    {
        if ($x != null) {
            if ($x instanceof ScheduledFutureTask) {
                $i = ScheduledFutureTask::$heapIndices->get($x->getXid(), 'index');
                // Sanity check; x could conceivably be a
                // ScheduledFutureTask from some other pool.
                if ($i !== false && $i >= 0 && $i < $this->size->get() && $x->equals(unserialize($this->queue->get((string) $i, 'task')))) {
                    return $i;
                }
            } else {
                for ($i = 0; $i < $this->size->get(); $i += 1) {
                    if ($x->equals(unserialize($this->queue->get((string) $i, 'task')))) {
                        return $i;
                    }
                }
            }
        }
        return -1;
    }

    public function contains($x = null): bool
    {
        $this->lock->lock();
        try {
            return $this->indexOf($x) !== -1;
        } finally {
            $this->lock->unlock();
        }
    }

    public function remove($el = null)
    {
        $this->lock->lock();
        try {
            $i = $this->indexOf($el);
            if ($i < 0) {
                return false;
            }

            $this->setIndex($this->queue->get((string) $i, 'task'), -1);
            $this->size->sub(1);
            $s = $this->size->get();
            $replacement = unserialize($this->queue->get((string) $s, 'task'));
            $this->queue->del((string) $s);
            if ($s != $i) {
                $this->siftDown($i, $replacement);
                if ($replacement->equals(unserialize($this->queue->get((string) $i, 'task')))) {
                    $this->siftUp($i, $replacement);
                }
            }
            return true;
        } finally {
            $this->lock->unlock();
        }
        return false;
    }

    public function size(): int
    {
        $this->lock->lock();
        try {
            return $this->size->get();
        } finally {
            $this->lock->unlock();
        }
    }

    public function isEmpty(): bool
    {
        return $this->size->get() === 0;
    }

    public function remainingCapacity(): int
    {
        return PHP_INT_MAX;
    }

    public function peek()
    {
        $this->lock->lock();
        try {
            if (($el = $this->queue->get((string) 0, 'task')) !== false) {
                return unserialize($el);
            }
        } finally {
            $this->lock->unlock();
        }
    }

    public function offer($e, ?ThreadInterface $thread = null): bool
    {
        $startTime = hrtime(true);
        $this->lock->lock(); //Interruptibly
        try {
            $i = $this->size->get();
            $this->size->add(1);
            if ($i === 0) {
                $this->queue->set((string) 0, ['xid' => $e->getXid(), 'task' => serialize($e)]);
                $this->setIndex($e, 0);                
            } else {
                $this->siftUp($i, $e);
            }
            if ($e->equals(unserialize($this->queue->get((string) 0, 'task')))) {
                $this->leader->set(-1);
                $this->available->signal();
            }
        } finally {
            $this->lock->unlock();
        }

        return true;
    }

    public function put($e): void
    {
        $this->offer($e);
    }

    public function add($e): bool
    {
        return $this->offer($e);
    }

    /**
     * Performs common bookkeeping for poll and take: Replaces
     * first element with last and sifts it down.  Call only when
     * holding lock.
     * @param f the task to remove and return
     */
    private function finishPoll(RunnableScheduledFutureInterface $f): RunnableScheduledFutureInterface
    {
        $this->size->sub(1);
        $s = $this->size->get();
        $x = unserialize($this->queue->get((string) $s, 'task'));
        $this->queue->del((string) $s);
        if ($s !== 0) {
            $this->siftDown(0, $x);
        }
        $this->setIndex($f, -1);
        return $f;
    }

    public function poll(?int $timeout = null, ?string $unit = null, ?ThreadInterface $thread = null)
    {
        if ($timeout === null && $unit === null) {
            $this->lock->lock();
            try {
                $first = ($ufirst = $this->queue->get((string) 0, 'task')) === false ? false : unserialize($ufirst);
                return ($first === false || $first->getDelay() > 0)
                    ? null
                    : $this->finishPoll($first);
            } finally {
                $this->lock->unlock();
            }
        } else {
            $nanos = TimeUnit::toNanos($timeout, $unit);
            //$this->lock->lockInterruptibly();
            $this->lock->lock();
            try {
                for (;;) {
                    $first = ($ufirst = $this->queue->get((string) 0, 'task')) === false ? false : unserialize($ufirst);
                    if ($first === false) {
                        if ($nanos <= 0) {
                            return null;
                        } else {
                            $nanos = $this->available->awaitNanos(null, $nanos);
                        }
                    } else {
                        $delay = $first->getDelay();
                        if ($delay <= 0) {
                            return $this->finishPoll($first);
                        }
                        if ($nanos <= 0) {
                            return null;
                        }
                        $first = null; // don't retain ref while waiting
                        if ($nanos < $delay || $this->leader->get() !== -1) {
                            $nanos = $this->available->awaitNanos(null, $nanos);
                        } else {
                            $thisThread = getmypid();
                            $this->leader->set($thisThread);
                            try {
                                $timeLeft = $this->available->awaitNanos(null, $delay);
                                $nanos -= $delay - $timeLeft;
                            } finally {
                                if ($this->leader->get() === $thisThread) {
                                    $this->leader->set(-1);
                                }
                            }
                        }
                    }
                }
            } finally {
                if ($this->leader->get() === -1 && $this->queue->get((string) 0, 'task') !== false) {
                    $this->available->signal();
                }
                $this->lock->unlock();
            }
        }
    }

    public function take(?ThreadInterface $thread = null)
    {
        $this->lock->lock(); //Interruptibly
        $first = null;
        try {
            for (;;) {
                $first = ($ufirst = $this->queue->get((string) 0, 'task')) === false ? false : unserialize($ufirst);
                if ($first === false) {
                    $this->available->await();
                } else {
                    $delay = $first->getDelay();
                    if ($delay <= 0) {
                        return $this->finishPoll($first);
                    }
                    $first = null; // don't retain ref while waiting
                    if ($this->leader->get() !== -1) {
                        $this->available->await();
                    } else {
                        $thisThread = getmypid();
                        $this->leader->set($thisThread);
                        try {
                            $this->available->awaitNanos(null, $delay);
                        } finally {
                            if ($this->leader->get() === $thisThread) {
                                $this->leader->set(-1);
                            }
                        }
                    }
                }
            }
        } finally {
            if ($this->leader->get() === -1 && $this->queue->get((string) 0, 'task') !== false) {
                $this->available->signal();
            }
            $this->lock->unlock();
        }
    }

    public function clear(): void
    {
        $this->lock->lock();
        try {
            foreach ($this->queue as $key => $value) {
                $this->queue->del($key);
            }
            $this->size->set(0);
        } finally {
            $this->lock->unlock();
        }
    }

    public function drainTo(&$c, int $maxElements = \PHP_INT_MAX): int
    {
        if ($maxElements <= 0) {
            return 0;
        }
        $this->lock->lock();
        try {
            $n = 0;
            for ($first = false;
                    $n < $maxElements
                        && ($first = ($ufirst = $this->queue->get((string) 0, 'task')) === false ? false : unserialize($ufirst)) !== false
                        && $first->getDelay() <= 0;) {
                $c[] = $first;   // In this order, in case add() throws.
                $this->finishPoll($first);
                $n += 1;
            }
            return $n;
        } finally {
            $this->lock->unlock();
        }
    }

    public function iterator()
    {
        $this->lock->lock();
        try {
            return new DelayedItr($this);
        } finally {
            $this->lock->unlock();
        }
    }
}
