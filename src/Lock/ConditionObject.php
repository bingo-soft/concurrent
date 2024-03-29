<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;
use Concurrent\TimeUnit;

class ConditionObject implements ConditionInterface
{
    /** First node of condition queue. */
    private $firstWaiter;
    /** Last node of condition queue. */
    private $lastWaiter;

    private $synchronizer;

    //Condition queue
    private $queue;

    /**
     * Creates a new {@code ConditionObject} instance.
     */
    public function __construct(?SynchronizerInterface $synchronizer)
    {
        $this->synchronizer = $synchronizer;
        $this->firstWaiter = new \Swoole\Atomic\Long(-1);
        $this->lastWaiter = new \Swoole\Atomic\Long(-1);

        $queue = new \Swoole\Table(128); //128 -> alloc(): mmap(21264) failed, Error: Too many open files in system[23]
        $queue->column('next', \Swoole\Table::TYPE_INT, 8);
        $queue->column('prev', \Swoole\Table::TYPE_INT, 8);
        $queue->column('pid', \Swoole\Table::TYPE_INT, 8);
        $queue->column('nextWaiter', \Swoole\Table::TYPE_INT, 8);
        $queue->column('waitStatus', \Swoole\Table::TYPE_INT, 8);
        $queue->create();
        $this->queue = $queue;
    }

    // Internal methods

    /**
     * Adds a new waiter to wait queue.
     * @return its new wait node
     */
    private function addConditionWaiter(?ThreadInterface $thread = null): array
    {
        $t = $this->lastWaiter;
        $lwData = $this->queue->get((string) $t->get());

        // If lastWaiter is cancelled, clean out.
        if ($t->get() !== -1 && $lwData['waitStatus'] !== Node::CONDITION) {
            $this->unlinkCancelledWaiters();
            $t = $this->lastWaiter;
        }
        $pid = $thread !== null ? $thread->pid : getmypid();
        $node = ['prev' => -1, 'next' => -1, 'pid' => $pid, 'nextWaiter' => -1, 'waitStatus' => Node::CONDITION];
        $this->queue->set((string) $pid, $node);
        if ($t->get() === -1) {
            $this->firstWaiter->set($pid);            
        } else {
            $nwData = $this->queue->get((string) $t->get());
            $nwData['nextWaiter'] = $pid;
            $this->queue->set((string) $t->get(), $nwData);
        }
        $this->lastWaiter->set($pid);
        return $node;
    }

    /**
     * Removes and transfers nodes until hit non-cancelled one or
     * null. Split out from signal in part to encourage compilers
     * to inline the case of no waiters.
     * @param first (non-null) the first node on condition queue
     */
    private function doSignal(int $first): void
    {
        do {
            $fData = $this->queue->get((string) $first);
            if ($fData['nextWaiter'] === -1) {
                $this->firstWaiter->set(-1);
                $this->lastWaiter->set(-1);                
            }
            $fData['nextWaiter'] = -1;
            $this->queue->del((string) $first);
        } while (!$this->synchronizer->transferForSignal($fData) &&
                 ($first = $this->firstWaiter->get()) !== -1);
    }

    /**
     * Removes and transfers all nodes.
     * @param first (non-null) the first node on condition queue
     */
    private function doSignalAll(int $first): void
    {
        $this->lastWaiter->set(-1);
        $this->firstWaiter->set(-1);
        do {
            $fData = $this->queue->get((string) $first);
            $next = $fData['nextWaiter'];
            $this->synchronizer->transferForSignal($fData);
            $this->queue->del((string) $first);
            $first = $next;
        } while ($first !== -1);
    }

    /**
     * Unlinks cancelled waiter nodes from condition queue.
     * Called only while holding lock. This is called when
     * cancellation occurred during condition wait, and upon
     * insertion of a new waiter when lastWaiter is seen to have
     * been cancelled. This method is needed to avoid garbage
     * retention in the absence of signals. So even though it may
     * require a full traversal, it comes into play only when
     * timeouts or cancellations occur in the absence of
     * signals. It traverses all nodes rather than stopping at a
     * particular target to unlink all pointers to garbage nodes
     * without requiring many re-traversals during cancellation
     * storms.
     */
    private function unlinkCancelledWaiters(): void
    {
        $t = $this->firstWaiter->get();
        $trail = null;
        while ($t !== -1) {
            $tData = $this->queue->get((string) $t);
            $next = $tData['nextWaiter'];
            if ($tData['waitStatus'] !== Node::CONDITION) {
                $tData['nextWaiter'] = -1;
                $this->queue->set((string) $t, $tData);
                if ($trail === null) {
                    $this->firstWaiter->set($next);
                } else {
                    $trailData = $this->queue->get((string) $trail);
                    $trailData['nextWaiter'] = $next;
                    $this->queue->set((string) $trail, $trailData);
                }
                if ($next === 0) {
                    $this->lastWaiter->set($trail);
                }
            } else {
                $trail = $t;
            }
            $t = $next;
        }
    }

    // public methods

    /**
     * Moves the longest-waiting thread, if one exists, from the
     * wait queue for this condition to the wait queue for the
     * owning lock.
     *
     * @throws IllegalMonitorStateException if {@link #isHeldExclusively}
     *         returns {@code false}
     */
    public function signal(?ThreadInterface $thread = null): void
    {
        if ($thread !== null && !$this->synchronizer->isHeldExclusively($thread)) {
            throw new \Exception("Illegal monitor state");
        }
        $first = $this->firstWaiter->get();
        if ($first !== -1) {
            $this->doSignal($first);
        }
    }

    /**
     * Moves all threads from the wait queue for this condition to
     * the wait queue for the owning lock.
     *
     * @throws IllegalMonitorStateException if {@link #isHeldExclusively}
     *         returns {@code false}
     */
    public function signalAll(?ThreadInterface $thread = null): void
    {
        if ($thread !== null && !$this->synchronizer->isHeldExclusively($thread)) {
            throw new \Exception("Illegal monitor state");
        }
        $first = $this->firstWaiter->get();
        if ($first !== -1) {
            $this->doSignalAll($first);
        }
    }

    /**
     * Implements uninterruptible condition wait.
     * <ol>
     * <li> Save lock state returned by {@link #getState}.
     * <li> Invoke {@link #release} with saved state as argument,
     *      throwing IllegalMonitorStateException if it fails.
     * <li> Block until signalled.
     * <li> Reacquire by invoking specialized version of
     *      {@link #acquire} with saved state as argument.
     * </ol>
     */
    public function awaitUninterruptibly(?ThreadInterface $thread = null): void
    {
        $node = $this->addConditionWaiter($thread);
        $savedState = $this->synchronizer->fullyRelease($thread);
        $interrupted = false;
        $pid = $thread !== null ? $thread->pid : getmypid();
        while (!$this->synchronizer->isOnSyncQueue($pid)) {
            LockSupport::park($thread);
            if ($thread->isInterrupted()) {
                $interrupted = true;
            }
        }
        if ($this->synchronizer->acquireQueued($thread, $node, $savedState) || $interrupted) {
            AbstractQueuedSynchronizer::selfInterrupt($thread);
        }
    }

    /*
     * For interruptible waits, we need to track whether to throw
     * InterruptedException, if interrupted while blocked on
     * condition, versus reinterrupt current thread, if
     * interrupted while blocked waiting to re-acquire.
     */

    /** Mode meaning to reinterrupt on exit from wait */
    private const REINTERRUPT =  1;
    /** Mode meaning to throw InterruptedException on exit from wait */
    private const THROW_IE    = -1;

    /**
     * Checks for interrupt, returning THROW_IE if interrupted
     * before signalled, REINTERRUPT if after signalled, or
     * 0 if not interrupted.
     */
    private function checkInterruptWhileWaiting(?ThreadInterface $thread = null): int
    {
        $pid = $thread !== null ? $thread->pid : getmypid();
        $node = $this->queue->get((string) $pid);
        return ($thread !== null && $thread->isInterrupted()) ?
            ($this->synchronizer->transferAfterCancelledWait($node) ? self::THROW_IE : self::REINTERRUPT) :
            0;
    }

    /**
     * Throws InterruptedException, reinterrupts current thread, or
     * does nothing, depending on mode.
     */
    private function reportInterruptAfterWait(?ThreadInterface $thread = null, int $interruptMode = 0): void
    {
        if ($interruptMode == self::THROW_IE) {
            throw new \Exception("Interrupted");
        } elseif ($interruptMode == self::REINTERRUPT) {
            AbstractQueuedSynchronizer::selfInterrupt($thread);
        }
    }
    /**
     * Implements timed condition wait.
     * <ol>
     * <li> If current thread is interrupted, throw InterruptedException.
     * <li> Save lock state returned by {@link #getState}.
     * <li> Invoke {@link #release} with saved state as argument,
     *      throwing IllegalMonitorStateException if it fails.
     * <li> Block until signalled, interrupted, or timed out.
     * <li> Reacquire by invoking specialized version of
     *      {@link #acquire} with saved state as argument.
     * <li> If interrupted while blocked in step 4, throw InterruptedException.
     * </ol>
     */
    public function awaitNanos(?ThreadInterface $thread = null, int $nanosTimeout = 0): int
    {
        if ($thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        $node = $this->addConditionWaiter($thread);
        $savedState = $this->synchronizer->fullyRelease($thread);
        $deadline = round(microtime(true)) * 1000 + $nanosTimeout;
        $interruptMode = 0;
        $pid = $thread !== null ? $thread->pid : getmypid();
        while (!$this->synchronizer->isOnSyncQueue($pid)) {
            if ($nanosTimeout <= 0) {
                $tData = $this->queue->get((string) $pid);
                $this->synchronizer->transferAfterCancelledWait($tData);
                break;
            }
            if ($nanosTimeout >= /*spinForTimeoutThreshold*/1000) {
                LockSupport::parkNanos($thread, $this->synchronizer, $nanosTimeout);
            }
            if (($interruptMode = $this->checkInterruptWhileWaiting($thread)) != 0) {
                break;
            }
            $nanosTimeout = $deadline - round(microtime(true)) * 1000;
        }
        if ($this->synchronizer->acquireQueued($thread, $node, $savedState) && $interruptMode != self::THROW_IE) {
            $interruptMode = self::REINTERRUPT;
        }
        $queue = $this->synchronizer->getQueue();
        $nData = $queue->get((string) $pid);
        if ($nData['nextWaiter'] !== 0) {
            $this->unlinkCancelledWaiters();
        }
        if ($interruptMode != 0) {
            $this->reportInterruptAfterWait($thread, $interruptMode);
        }
        return $deadline - round(microtime(true)) * 1000;
    }

    /**
     * Implements absolute timed condition wait.
     * <ol>
     * <li> If current thread is interrupted, throw InterruptedException.
     * <li> Save lock state returned by {@link #getState}.
     * <li> Invoke {@link #release} with saved state as argument,
     *      throwing IllegalMonitorStateException if it fails.
     * <li> Block until signalled, interrupted, or timed out.
     * <li> Reacquire by invoking specialized version of
     *      {@link #acquire} with saved state as argument.
     * <li> If interrupted while blocked in step 4, throw InterruptedException.
     * <li> If timed out while blocked in step 4, return false, else true.
     * </ol>
     */
    public function awaitUntil(?ThreadInterface $thread = null, \DateTime $deadline = null): bool
    {
        $abstime = $deadline->getTime()->getTimestamp();
        if ($thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        $node = $this->addConditionWaiter($thread);
        $savedState = $this->synchronizer->fullyRelease($thread);
        $timedout = false;
        $interruptMode = 0;
        $pid = $thread !== null ? $thread->pid : getmypid();
        while (!$this->synchronizer->isOnSyncQueue($pid)) {
            if (time() > $abstime) {
                $tData = $this->queue->get((string) $pid);
                $timedout = $this->synchronizer->transferAfterCancelledWait($tData);
                break;
            }
            LockSupport::parkUntil($thread, $this->synchronizer, $abstime);
            if (($interruptMode = $this->checkInterruptWhileWaiting($thread)) != 0) {
                break;
            }
        }
        if ($this->synchronizer->acquireQueued($thread, $node, $savedState) && $interruptMode != self::THROW_IE) {
            $interruptMode = self::REINTERRUPT;
        }
        $queue = $this->synchronizer->getQueue();
        $nData = $queue->get((string) $pid);
        if ($nData['nextWaiter'] !== 0) {
            $this->unlinkCancelledWaiters();
        }
        if ($interruptMode != 0) {
            $this->reportInterruptAfterWait($thread, $interruptMode);
        }
        return !$timedout;
    }

    /**
     * Implements timed condition wait.
     * <ol>
     * <li> If current thread is interrupted, throw InterruptedException.
     * <li> Save lock state returned by {@link #getState}.
     * <li> Invoke {@link #release} with saved state as argument,
     *      throwing IllegalMonitorStateException if it fails.
     * <li> Block until signalled, interrupted, or timed out.
     * <li> Reacquire by invoking specialized version of
     *      {@link #acquire} with saved state as argument.
     * <li> If interrupted while blocked in step 4, throw InterruptedException.
     * <li> If timed out while blocked in step 4, return false, else true.
     * </ol>
     */
    public function await(?ThreadInterface $thread = null, ?int $time = null, ?string $unit = null)
    {
        $pid = $thread !== null ? $thread->pid : getmypid();
        if ($time === null && $unit === null) {
            if ($thread !== null && $thread->isInterrupted()) {
                throw new \Exception("Interrupted");
            }
            $node = $this->addConditionWaiter($thread);           
            $savedState = $this->synchronizer->fullyRelease($thread);
            $interruptMode = 0;
            while (!$this->synchronizer->isOnSyncQueue($pid)) {  
                LockSupport::park($thread/*, $this->synchronizer*/); 
                if (($interruptMode = $this->checkInterruptWhileWaiting($thread)) != 0)
                    break;
            }  
            if ($this->synchronizer->acquireQueued($thread, $node, $savedState) && $interruptMode != self::THROW_IE) {
                $interruptMode = self::REINTERRUPT;
            }
            $queue = $this->synchronizer->getQueue();
            $nData = $queue->get((string) $pid);
            if ($nData['nextWaiter'] !== 0) {
                $this->unlinkCancelledWaiters();
            }
            if ($interruptMode != 0) {
                $this->reportInterruptAfterWait($thread, $interruptMode);
            }
        } else {
            $nanosTimeout = TimeUnit::toNanos($time, $unit);
            if ($thread->isInterrupted()) {
                throw new \Exception("Interrupted");
            }
            $node = $this->addConditionWaiter($thread);
            $savedState = $this->synchronizer->fullyRelease($thread);
            $deadline = round(microtime(true)) * 1000 + $nanosTimeout;
            $timedout = false;
            $interruptMode = 0;
            while (!$this->synchronizer->isOnSyncQueue($pid)) {
                if ($nanosTimeout <= 0) {
                    $tData = $this->queue->get((string) $pid);
                    $timedout = $this->synchronizer->transferAfterCancelledWait($tData);
                    break;
                }
                if ($nanosTimeout >= /*spinForTimeoutThreshold*/1000) {
                    LockSupport::parkNanos($thread, $this->synchronizer, $nanosTimeout);
                }
                if (($interruptMode = $this->checkInterruptWhileWaiting($thread)) != 0) {
                    break;
                }
                $nanosTimeout = $deadline - round(microtime(true)) * 1000;
            }
            if ($this->synchronizer->acquireQueued($thread, $node, $savedState) && $interruptMode != self::THROW_IE) {
                $interruptMode = self::REINTERRUPT;
            }
            $queue = $this->synchronizer->getQueue();
            $nData = $queue->get((string) $pid);
            if ($nData['nextWaiter'] !== 0) {
                $this->unlinkCancelledWaiters();
            }
            if ($interruptMode != 0) {
                $this->reportInterruptAfterWait($thread, $interruptMode);
            }
            return !$timedout;
        }
    }

    //  support for instrumentation

    /**
     * Returns true if this condition was created by the given
     * synchronization object.
     *
     * @return {@code true} if owned
     */
    public function isOwnedBy(SynchronizerInterface $sync): bool
    {
        return $sync == $this;
    }

    /**
     * Queries whether any threads are waiting on this condition.
     * Implements {@link AbstractQueuedSynchronizer#hasWaiters(ConditionObject)}.
     *
     * @return {@code true} if there are any waiting threads
     * @throws IllegalMonitorStateException if {@link #isHeldExclusively}
     *         returns {@code false}
     */
    protected function hasWaiters(): bool
    {
        if (!$this->synchronizer->isHeldExclusively()) {
            throw new \Exception("Illegal monitor state");
        }
        for ($w = $this->firstWaiter->get(); $w !== -1; ) {
            $wData = $this->queue->get((string) $w);
            if ($wData['waitStatus'] == Node::CONDITION) {
                return true;
            }
            $w = $wData['nextWaiter'];
        }
        return false;
    }

    /**
     * Returns an estimate of the number of threads waiting on
     * this condition.
     * Implements {@link AbstractQueuedSynchronizer#getWaitQueueLength(ConditionObject)}.
     *
     * @return the estimated number of waiting threads
     * @throws IllegalMonitorStateException if {@link #isHeldExclusively}
     *         returns {@code false}
     */
    protected function getWaitQueueLength(): int
    {
        if (!$this->synchronizer->isHeldExclusively()) {
            throw new \Exception("Illegal monitor state");
        }
        $n = 0;
        for ($w = $this->firstWaiter->get(); $w !== -1; ) {
            $wData = $this->queue->get((string) $w);
            if ($wData['waitStatus'] == Node::CONDITION) {
                $n += 1;
            }
            $w = $wData['nextWaiter'];
        }
        return $n;
    }

    /**
     * Returns a collection containing those threads that may be
     * waiting on this Condition.
     * Implements {@link AbstractQueuedSynchronizer#getWaitingThreads(ConditionObject)}.
     *
     * @return the collection of threads
     * @throws IllegalMonitorStateException if {@link #isHeldExclusively}
     *         returns {@code false}
     */
    protected function getWaitingThreads(): array
    {
        if (!$this->synchronizer->isHeldExclusively()) {
            throw new \Exception("Illegal monitor state");
        }
        $list = [];
        for ($w = $this->firstWaiter->get(); $w !== -1; ) {
            $wData = $this->queue->get((string) $w);
            if ($wData['waitStatus'] == Node::CONDITION) {
                $t = $wData['pid'];
                if ($t !== 0) {
                    $list[] = $t;
                }
            }
            $w = $wData['nextWaiter'];
        }
        return $list;
    }
}
