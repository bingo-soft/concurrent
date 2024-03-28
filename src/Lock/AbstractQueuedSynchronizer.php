<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

abstract class AbstractQueuedSynchronizer extends AbstractOwnableSynchronizer
{
    /**
     * Head of the wait queue, lazily initialized.  Except for
     * initialization, it is modified only via method setHead.  Note:
     * If head exists, its waitStatus is guaranteed not to be
     * CANCELLED.
     */
    public $head;

    /**
     * Tail of the wait queue, lazily initialized.  Modified only via
     * method enq to add new wait node.
     */
    public $tail;

    /**
     * The synchronization state.
     */
    private $state;

    //Synchronization queue
    private $queue;

    /**
     * Creates a new {@code AbstractQueuedSynchronizer} instance
     * with initial synchronization state of zero.
     */
    public function __construct()
    {
        $this->state = new \Swoole\Atomic\Long(0);

        $this->head = new \Swoole\Atomic\Long(-1);
        $this->tail = new \Swoole\Atomic\Long(-1);

        $queue = new \Swoole\Table(128); // 128 -> alloc(): mmap(21264) failed, Error: Too many open files in system[23]
        $queue->column('next', \Swoole\Table::TYPE_INT, 8);
        $queue->column('prev', \Swoole\Table::TYPE_INT, 8);
        $queue->column('pid', \Swoole\Table::TYPE_INT, 8);
        $queue->column('nextWaiter', \Swoole\Table::TYPE_INT, 8);
        $queue->column('waitStatus', \Swoole\Table::TYPE_INT, 8);
        $queue->create();
        $this->queue = $queue;
    }
    
    public function getQueue(): \Swoole\Table
    {
        return $this->queue;
    }

    /**
     * Returns the current value of synchronization state.
     * This operation has memory semantics of a {@code volatile} read.
     * @return current state value
     */
    public function getState(): int
    {
        return $this->state->get();
    }

    /**
     * Sets the value of synchronization state.
     * This operation has memory semantics of a {@code volatile} write.
     * @param newState the new state value
     */
    public function setState(int $newState): void
    {
        $this->state->set($newState);
    }

    /**
     * Atomically sets synchronization state to the given updated
     * value if the current state value equals the expected value.
     * This operation has memory semantics of a {@code volatile} read
     * and write.
     *
     * @param expect the expected value
     * @param update the new value
     * @return {@code true} if successful. False return indicates that the actual
     *         value was not equal to the expected value.
     */
    public function compareAndSetState(int $expect, int $update): bool
    {
        // See below for intrinsics setup to support this
        if ($this->state->get() == $expect) {
            $this->state->set($update);
            return true;
        }        
        return false;
    }

    // Queuing utilities

    /**
     * The number of nanoseconds for which it is faster to spin
     * rather than to use timed park. A rough estimate suffices
     * to improve responsiveness with very short timeouts.
     */
    static $spinForTimeoutThreshold = 1000;

    /**
     * Inserts node into queue, initializing if necessary. See picture above.
     * @param node the node to insert
     * @return node's predecessor
     */
    public function enq(array &$node): int
    {
        for (;;) {            
            $t = $this->tail->get();
            if ($t === -1) { // Must initialize
                if ($this->initHead()) {
                    $this->queue->set((string) $this->head->get(), ['next' => -1, 'prev' => -1, 'pid' => 0, 'nextWaiter' =>  -1, 'waitStatus' => 0]);
                    $this->tail->set($this->head->get());
                }
            } else {
                $pid = $node['pid'];                
                $node['prev'] = $t;
                $this->queue->set((string) $pid, $node);
                if ($this->compareAndSetTail($t, $node['pid'])) {
                    $tailData = $this->queue->get((string) $t);
                    $tailData['next'] = $pid;
                    $this->queue->set((string) $t, $tailData);
                    return $t;
                }
            }
        }
    }

    /**
     * Creates and enqueues node for current thread and given mode.
     *
     * @param mode 1 for exclusive, 2 for shared
     */
    private function addWaiter(?ThreadInterface $thread = null, int $mode = 0)
    {
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        if ($mode == 1) {
            $nData = ['next' => -1, 'prev' => -1, 'pid' => $pid, 'nextWaiter' => -1, 'waitStatus' => 0];
        } else {
            $nData = ['next' => -1, 'prev' => -1, 'pid' => $pid, 'nextWaiter' => 0, 'waitStatus' => 0];
        }
        $this->queue->set((string) $pid, $nData);
        // Try the fast path of enq; backup to full enq on failure
        $pred = $this->tail;
        if ($pred->get() !== -1) {
            $nData['prev'] = $pred->get();
            if ($this->compareAndSetTail($pred->get(), $pid)) {
                $predData = $this->queue->get((string) $pred->get());
                $predData['next'] = $pid;
                $this->queue->set((string) $pred->get(), $predData);
                return $nData;
            }
        }
        $this->enq($nData);
        return $nData;
    }

    /**
     * Sets head of queue to be node, thus dequeuing. Called only by
     * acquire methods.  Also nulls out unused fields for sake of GC
     * and to suppress unnecessary signals and traversals.
     *
     * @param node the node
     */
    private function setHead(int $node): void
    {
        $nData = $this->queue->get((string) $node);
        $this->queue->del((string) $node);
        $this->head->set(0);

        $next = $nData['next'];

        if ($this->tail->get() == $node) {
            $this->tail->set(0);
            $next = -1;
        } else {
            $nextData = $this->queue->get((string) $next);
            $nextData['prev'] = $nData['prev'];
            $this->queue->set((string) $next, $nextData);
        }
        //@TODO. Check of setting nextWaiter and waitStatus
        $this->queue->set((string) $this->head->get(), ['next' => $next, 'prev' => -1, 'pid' => 0, 'nextWaiter' =>  $nData['nextWaiter'], 'waitStatus' => $nData['waitStatus']]);
    }

    /**
     * Wakes up node's successor, if one exists.
     *
     * @param node the node
     */
    private function unparkSuccessor(int $node): void
    {
        /*
         * If status is negative (i.e., possibly needing signal) try
         * to clear in anticipation of signalling.  It is OK if this
         * fails or if status is changed by waiting thread.
         */
        $nData = $this->queue->get((string) $node);
        $ws = $nData['waitStatus'];
        if ($ws < 0) {
            $this->compareAndSetWaitStatus($nData, $ws, 0);
        }

        /*
         * Thread to unpark is held in successor, which is normally
         * just the next node.  But if cancelled or apparently null,
         * traverse backwards from tail to find the actual
         * non-cancelled successor.
         */
        $s = $nData['next'];
        $sData = $this->queue->get((string) $s);
        if ($sData === false || $sData['waitStatus'] > 0) {
            $s = null;
            for ($t = $this->tail->get(); $t !== -1 && $t != $nData['pid']; $t = ($this->queue->get((string) $t, 'prev')) ) {
                $tData = $this->queue->get((string) $t);
                if ($tData['waitStatus'] <= 0) {
                    $s = $t;
                }
            }
        }
        if ($s !== null) {
            LockSupport::unpark($s);
        }
    }

    /**
     * Release action for shared mode -- signals successor and ensures
     * propagation. (Note: For exclusive mode, release just amounts
     * to calling unparkSuccessor of head if it needs signal.)
     */
    private function doReleaseShared(): void
    {
        /*
         * Ensure that a release propagates, even if there are other
         * in-progress acquires/releases.  This proceeds in the usual
         * way of trying to unparkSuccessor of head if it needs
         * signal. But if it does not, status is set to PROPAGATE to
         * ensure that upon release, propagation continues.
         * Additionally, we must loop in case a new node is added
         * while we are doing this. Also, unlike other uses of
         * unparkSuccessor, we need to know if CAS to reset status
         * fails, if so rechecking.
         */
        for (;;) {
            $h = $this->head;
            if ($h->get() !== -1 && $h->get() !== $this->tail->get()) {
                $hData = $this->queue->get((string) $h->get());
                $ws = $hData['waitStatus'];
                if ($ws == Node::SIGNAL) {
                    if (!$this->compareAndSetWaitStatus($hData, Node::SIGNAL, 0)) {
                        continue;            // loop to recheck cases
                    }
                    $this->unparkSuccessor($h->get());
                } elseif ($ws === 0 && !$this->compareAndSetWaitStatus($hData, $ws, Node::PROPAGATE)) {
                    continue;                // loop on failed CAS
                }
            }
            if ($h->get() == $this->head->get()) { // loop if head changed
                break;
            }
        }
    }

    /**
     * Sets head of queue, and checks if successor may be waiting
     * in shared mode, if so propagating if either propagate > 0 or
     * PROPAGATE status was set.
     *
     * @param node the node
     * @param propagate the return value from a tryAcquireShared
     */
    private function setHeadAndPropagate(int $node, int $propagate): void
    {
        $h = $this->head; // Record old head for check below
        $hData = $this->queue->get((string) $h->get());
        $this->setHead($node);
        /*
         * Try to signal next queued node if:
         *   Propagation was indicated by caller,
         *     or was recorded (as h.waitStatus either before
         *     or after setHead) by a previous operation
         *     (note: this uses sign-check of waitStatus because
         *      PROPAGATE status may transition to SIGNAL.)
         * and
         *   The next node is waiting in shared mode,
         *     or we don't know, because it appears null
         *
         * The conservatism in both of these checks may cause
         * unnecessary wake-ups, but only when there are multiple
         * racing acquires/releases, so most need signals now or soon
         * anyway.
         */
        if ($propagate > 0 || $h->get() == -1 || $hData['waitStatus'] < 0 ||
            ($h = $this->head)->get() == -1 || ($hData = $this->queue->get((string) $h->get()))['waitStatus'] < 0) {
            $nData = $this->queue->get((string) $node);
            $s = $nData['next'];
            if ($s == 0) {
                $this->doReleaseShared();
            } else {
                $sData = $this->queue->get((string) $h->get());
                if ($sData !== false) {
                    //Check if is shared
                    $n = $sData['nextWaiter'];
                    $nData = $this->queue->get((string) $n);
                    //nextWaiter -> empty Node()
                    if ($nData !== false && $nData['pid'] == 0 && $nData['waitStatus'] == 0 && $nData['nextWaiter'] == 0) {
                        $this->doReleaseShared();
                    }
                }
            }
        }
    }

    // Utilities for various versions of acquire

    /**
     * Cancels an ongoing attempt to acquire.
     *
     * @param node the node
     */
    private function cancelAcquire(?int $node): void
    {
        // Ignore if node doesn't exist
        if ($node === null) {
            return;
        }

        // Skip cancelled predecessors
        $nodeData = $this->queue->get((string) $node);
        $pred = $nodeData['prev'];
        $predData = $this->queue->get((string) $pred);        
        while ($predData['waitStatus'] > 0) {
            $pred = $predData['prev'];

            $nodeData['prev'] = $pred;
            $this->queue->set((string) $node, $nodeData);

            $nData = $this->queue->get((string) $pred);
        }
        // predNext is the apparent node to unsplice. CASes below will
        // fail if not, in which case, we lost race vs another cancel
        // or signal, so no further action is necessary.
 
        $predNext = $this->queue->get((string) $pred, 'next');

        // Can use unconditional write instead of CAS here.
        // After this atomic step, other Nodes can skip past us.
        // Before, we are free of interference from other threads.
        $nodeData['waitStatus'] = Node::CANCELLED;
        $this->queue->set((string) $node, $nodeData);

        // If we are the tail, remove ourselves.
        if ($node == $this->tail->get() && $this->compareAndSetTail($node, $pred)) {
            $this->compareAndSetNext($pred, $predNext, null);
        } else {
            // If successor needs signal, try to set pred's next-link
            // so it will get one. Otherwise wake it up to propagate.
            $ws = null;
            if ($pred != $this->head->get() &&
                (($ws = $this->queue->get((string) $pred, 'waitStatus')) == Node::SIGNAL ||
                 ($ws <= 0 && $this->compareAndSetWaitStatus($predData, $ws, Node::SIGNAL))) &&
                 $this->queue->get((string) $pred, 'pid') !== 0) {
                $next = $nodeData['next'];
                if ($next !== 0 && $this->queue->get((string) $next, 'waitStatus') <= 0) {
                    $this->compareAndSetNext($pred, $predNext, $next);
                }
            } else {
                $this->unparkSuccessor($node);
            }

            //node.next = node;
            $nodeData = $this->queue->get((string) $node);
            $nodeData['next'] = $node;
            $this->queue->set((string) $node, $nodeData);      
        }
    }

    /**
     * Checks and updates status for a node that failed to acquire.
     * Returns true if thread should block. This is the main signal
     * control in all acquire loops.  Requires that pred == node.prev.
     *
     * @param pred node's predecessor holding status
     * @param node the node
     * @return {@code true} if thread should block
     */
    private function shouldParkAfterFailedAcquire(int $pred, int $node): bool
    {
        $pData = $this->queue->get((string) $pred);
        $nData = $this->queue->get((string) $node);
        $ws = $pData['waitStatus'];
        if ($ws == Node::SIGNAL) {
            /*
             * This node has already set status asking a release
             * to signal it, so it can safely park.
             */
            return true;
        }
        if ($ws > 0) {
            /*
             * Predecessor was cancelled. Skip over predecessors and
             * indicate retry.
             */
            do {
                $pred = $pData['prev'];
                $nData['prev'] = $pred;
                $this->queue->set((string) $node, $nData);
                $pData = $this->queue->get((string) $pred);
            } while ($pData['waitStatus'] > 0);
            $pData['next'] = $node;
            $this->queue->set((string) $pData['pid'], $nData);
        } else {
            /*
             * waitStatus must be 0 or PROPAGATE.  Indicate that we
             * need a signal, but don't park yet.  Caller will need to
             * retry to make sure it cannot acquire before parking.
             */
            $this->compareAndSetWaitStatus($pData, $ws, Node::SIGNAL);
        }
        return false;
    }

    /**
     * Convenience method to interrupt current thread.
     */
    public static function selfInterrupt(?ThreadInterface $thread = null): void
    {
        $thread->interrupt();
    }

    /**
     * Convenience method to park and then check if interrupted
     *
     * @return {@code true} if interrupted
     */
    private function parkAndCheckInterrupt(?ThreadInterface $thread = null): bool
    {
        LockSupport::park($thread/*, $this*/);
        return $thread !== null ? $thread->isInterrupted() : false;
    }

    /*
     * Various flavors of acquire, varying in exclusive/shared and
     * control modes.  Each is mostly the same, but annoyingly
     * different.  Only a little bit of factoring is possible due to
     * interactions of exception mechanics (including ensuring that we
     * cancel if tryAcquire throws exception) and other control, at
     * least not without hurting performance too much.
     */

    /**
     * Acquires in exclusive uninterruptible mode for thread already in
     * queue. Used by condition wait methods as well as acquire.
     *
     * @param node the node
     * @param arg the acquire argument
     * @return {@code true} if interrupted while waiting
     */
    public function acquireQueued(?ThreadInterface $thread = null, array $node = [], int $arg = 0): bool
    {
        $failed = true;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        try {
            $interrupted = false;
            for (;;) {
                $predData = $this->queue->get((string) $pid);
                $p = $predData['prev'];
                if ($p == $this->head->get() && $this->tryAcquire($thread, $arg)) {
                    $this->setHead($pid);
                    $failed = false;
                    return $interrupted;
                }
                if ($this->shouldParkAfterFailedAcquire($p, $pid) && $this->parkAndCheckInterrupt($thread)) {
                    $interrupted = true;
                }
            }
        } finally {
            if ($failed) {
                $this->cancelAcquire($pid);
            }
        }
    }

    /**
     * Acquires in exclusive interruptible mode.
     * @param arg the acquire argument
     */
    private function doAcquireInterruptibly(?ThreadInterface $thread = null, int $arg = 0): void
    {
        $this->addWaiter($thread, 1);
        $failed = true;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        try {
            for (;;) {
                $nData = $this->queue->get((string) $pid);  
                $p = $nData['prev'];
                if ($p == $this->head->get() && $this->tryAcquire($thread, $arg)) {
                    $this->setHead($pid);
                    //$pData = $this->queue->get((string) $p);
                    //$pData['next'] = -1;
                    //$this->queue->set((string) $p, $pData);  
                    $failed = false;                    
                    return;
                }
                if ($this->shouldParkAfterFailedAcquire($p, $pid) && $this->parkAndCheckInterrupt($thread)) {
                    throw new \Exception("Interrupted");
                }
            }
        } finally {
            if ($failed) {
                $this->cancelAcquire($pid);
            }
        }
    }

    /**
     * Acquires in exclusive timed mode.
     *
     * @param arg the acquire argument
     * @param nanosTimeout max wait time
     * @return {@code true} if acquired
     */
    private function doAcquireNanos(?ThreadInterface $thread = null, int $arg = 0, int $nanosTimeout = 0): bool
    {
        if ($nanosTimeout <= 0) {
            return false;
        }
        $deadline = round(microtime(true)) * 1000 + $nanosTimeout;
        $this->addWaiter($thread, 1);
        $failed = true;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        try {
            for (;;) {
                $nData = $this->queue->get((string) $pid);  
                $p = $nData['prev'];
                if ($p == $this->head->get() && $this->tryAcquire($thread, $arg)) {
                    $this->setHead($pid);
                    //$pData = $this->queue->get((string) $p);
                    //$pData['next'] = -1;
                    //$this->queue->set((string) $p, $pData);
                    $failed = false;
                    return true;
                }
                $nanosTimeout = $deadline - round(microtime(true)) * 1000;
                if ($nanosTimeout <= 0) {
                    return false;
                }
                if ($this->shouldParkAfterFailedAcquire($p, $pid) &&
                    $nanosTimeout > self::$spinForTimeoutThreshold) {
                    LockSupport::parkNanos($thread, $this, $nanosTimeout);
                }
                if ($thread->isInterrupted()) {
                    throw new \Exception("Interrupted");
                }
            }
        } finally {
            if ($failed) {
                $this->cancelAcquire($pid);
            }
        }
    }

    /**
     * Acquires in shared uninterruptible mode.
     * @param arg the acquire argument
     */
    private function doAcquireShared(?ThreadInterface $thread = null, int $arg = 0): void
    {
        $this->addWaiter($thread, 2);
        $failed = true;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        try {
            $interrupted = false;
            for (;;) {
                $nData = $this->queue->get((string) $pid);  
                $p = $nData['prev'];
                if ($p == $this->head->get()) {
                    $r = $this->tryAcquireShared($thread, $arg);
                    if ($r >= 0) {
                        $this->setHeadAndPropagate($pid, $r);
                        $pData = $this->queue->get((string) $p);
                        $pData['next'] = -1;
                        $this->queue->set((string) $p, $pData);

                        if ($interrupted) {
                            self::selfInterrupt($thread);
                        }
                        $failed = false;
                        return;
                    }
                }
                if ($this->shouldParkAfterFailedAcquire($p, $pid) && $this->parkAndCheckInterrupt($thread)) {
                    $interrupted = true;
                }
            }
        } finally {
            if ($failed) {
                $this->cancelAcquire($pid);
            }
        }
    }

    /**
     * Acquires in shared interruptible mode.
     * @param arg the acquire argument
     */
    private function doAcquireSharedInterruptibly(?ThreadInterface $thread = null, int $arg = 0): void
    {
        $this->addWaiter($thread, 2);
        $failed = true;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        try {
            for (;;) {
                $nData = $this->queue->get((string) $pid);  
                $p = $nData['prev'];
                if ($p == $this->head->get()) {
                    $r = $this->tryAcquireShared($thread, $arg);
                    if ($r >= 0) {
                        $this->setHeadAndPropagate($pid, $r);
                        $pData = $this->queue->get((string) $p);
                        $pData['next'] = -1;
                        $this->queue->set((string) $p, $pData);

                        $failed = false;
                        return;
                    }
                }
                if ($this->shouldParkAfterFailedAcquire($p, $pid) && $this->parkAndCheckInterrupt($thread)) {
                    throw new \Exception("Interrupted");
                }
            }
        } finally {
            if ($failed) {
                $this->cancelAcquire($pid);
            }
        }
    }

    /**
     * Acquires in shared timed mode.
     *
     * @param arg the acquire argument
     * @param nanosTimeout max wait time
     * @return {@code true} if acquired
     */
    private function doAcquireSharedNanos(?ThreadInterface $thread = null, int $arg = 0, int $nanosTimeout = 0): bool
    {
        if ($nanosTimeout <= 0) {
            return false;
        }
        $deadline = round(microtime(true)) * 1000 + $nanosTimeout;
        $this->addWaiter($thread, 2);
        $failed = true;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        try {
            for (;;) {
                $nData = $this->queue->get((string) $pid);  
                $p = $nData['prev'];
                if ($p == $this->head->get()) {
                    $r = $this->tryAcquireShared($thread, $arg);
                    if ($r >= 0) {
                        $this->setHeadAndPropagate($pid, $r);
                        $pData = $this->queue->get((string) $p);
                        $pData['next'] = -1;
                        $this->queue->set((string) $p, $pData);

                        $failed = false;
                        return true;
                    }
                }
                $nanosTimeout = $deadline - round(microtime(true)) * 1000;
                if ($nanosTimeout <= 0) {
                    return false;
                }
                if ($this->shouldParkAfterFailedAcquire($p, $pid) &&
                    $nanosTimeout > self::$spinForTimeoutThreshold) {
                    LockSupport::parkNanos($thread, $this, $nanosTimeout);
                }
                if ($thrfead->isInterrupted()) {
                    throw new \Exception("Interrupted");
                }
            }
        } finally {
            if ($failed) {
                $this->cancelAcquire($pid);
            }
        }
    }

    // Main exported methods

    /**
     * Attempts to acquire in exclusive mode. This method should query
     * if the state of the object permits it to be acquired in the
     * exclusive mode, and if so to acquire it.
     *
     * <p>This method is always invoked by the thread performing
     * acquire.  If this method reports failure, the acquire method
     * may queue the thread, if it is not already queued, until it is
     * signalled by a release from some other thread. This can be used
     * to implement method {@link Lock#tryLock()}.
     *
     * <p>The default
     * implementation throws {@link UnsupportedOperationException}.
     *
     * @param arg the acquire argument. This value is always the one
     *        passed to an acquire method, or is the value saved on entry
     *        to a condition wait.  The value is otherwise uninterpreted
     *        and can represent anything you like.
     * @return {@code true} if successful. Upon success, this object has
     *         been acquired.
     * @throws IllegalMonitorStateException if acquiring would place this
     *         synchronizer in an illegal state. This exception must be
     *         thrown in a consistent fashion for synchronization to work
     *         correctly.
     * @throws UnsupportedOperationException if exclusive mode is not supported
     */
    public function tryAcquire(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        throw new \Exception("UnsupportedOperation");
    }

    /**
     * Attempts to set the state to reflect a release in exclusive
     * mode.
     *
     * <p>This method is always invoked by the thread performing release.
     *
     * <p>The default implementation throws
     * {@link UnsupportedOperationException}.
     *
     * @param arg the release argument. This value is always the one
     *        passed to a release method, or the current state value upon
     *        entry to a condition wait.  The value is otherwise
     *        uninterpreted and can represent anything you like.
     * @return {@code true} if this object is now in a fully released
     *         state, so that any waiting threads may attempt to acquire;
     *         and {@code false} otherwise.
     * @throws IllegalMonitorStateException if releasing would place this
     *         synchronizer in an illegal state. This exception must be
     *         thrown in a consistent fashion for synchronization to work
     *         correctly.
     * @throws UnsupportedOperationException if exclusive mode is not supported
     */
    public function tryRelease(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        throw new \Exception("Unsupported operation");
    }

    /**
     * Attempts to acquire in shared mode. This method should query if
     * the state of the object permits it to be acquired in the shared
     * mode, and if so to acquire it.
     *
     * <p>This method is always invoked by the thread performing
     * acquire.  If this method reports failure, the acquire method
     * may queue the thread, if it is not already queued, until it is
     * signalled by a release from some other thread.
     *
     * <p>The default implementation throws {@link
     * UnsupportedOperationException}.
     *
     * @param arg the acquire argument. This value is always the one
     *        passed to an acquire method, or is the value saved on entry
     *        to a condition wait.  The value is otherwise uninterpreted
     *        and can represent anything you like.
     * @return a negative value on failure; zero if acquisition in shared
     *         mode succeeded but no subsequent shared-mode acquire can
     *         succeed; and a positive value if acquisition in shared
     *         mode succeeded and subsequent shared-mode acquires might
     *         also succeed, in which case a subsequent waiting thread
     *         must check availability. (Support for three different
     *         return values enables this method to be used in contexts
     *         where acquires only sometimes act exclusively.)  Upon
     *         success, this object has been acquired.
     * @throws IllegalMonitorStateException if acquiring would place this
     *         synchronizer in an illegal state. This exception must be
     *         thrown in a consistent fashion for synchronization to work
     *         correctly.
     * @throws UnsupportedOperationException if shared mode is not supported
     */
    public function tryAcquireShared(?ThreadInterface $thread = null, int $arg = 0): int
    {
        throw new \Exception("Unsupported operation");
    }

    /**
     * Attempts to set the state to reflect a release in shared mode.
     *
     * <p>This method is always invoked by the thread performing release.
     *
     * <p>The default implementation throws
     * {@link UnsupportedOperationException}.
     *
     * @param arg the release argument. This value is always the one
     *        passed to a release method, or the current state value upon
     *        entry to a condition wait.  The value is otherwise
     *        uninterpreted and can represent anything you like.
     * @return {@code true} if this release of shared mode may permit a
     *         waiting acquire (shared or exclusive) to succeed; and
     *         {@code false} otherwise
     * @throws IllegalMonitorStateException if releasing would place this
     *         synchronizer in an illegal state. This exception must be
     *         thrown in a consistent fashion for synchronization to work
     *         correctly.
     * @throws UnsupportedOperationException if shared mode is not supported
     */
    public function tryReleaseShared(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        throw new \Exception("Unsupported operation");
    }

    /**
     * Returns {@code true} if synchronization is held exclusively with
     * respect to the current (calling) thread.  This method is invoked
     * upon each call to a non-waiting {@link ConditionObject} method.
     * (Waiting methods instead invoke {@link #release}.)
     *
     * <p>The default implementation throws {@link
     * UnsupportedOperationException}. This method is invoked
     * internally only within {@link ConditionObject} methods, so need
     * not be defined if conditions are not used.
     *
     * @return {@code true} if synchronization is held exclusively;
     *         {@code false} otherwise
     * @throws UnsupportedOperationException if conditions are not supported
     */
    public function isHeldExclusively(?ThreadInterface $thread = null): bool
    {
        throw new UnsupportedOperationException();
    }

    /**
     * Acquires in exclusive mode, ignoring interrupts.  Implemented
     * by invoking at least once {@link #tryAcquire},
     * returning on success.  Otherwise the thread is queued, possibly
     * repeatedly blocking and unblocking, invoking {@link
     * #tryAcquire} until success.  This method can be used
     * to implement method {@link Lock#lock}.
     *
     * @param arg the acquire argument.  This value is conveyed to
     *        {@link #tryAcquire} but is otherwise uninterpreted and
     *        can represent anything you like.
     */
    public function acquire(?ThreadInterface $thread = null, int $arg = 0)
    {
        if (!$this->tryAcquire($thread, $arg)) {
            $node = $this->addWaiter($thread, 1);
            if ($this->acquireQueued($thread, $node, $arg)) {
                self::selfInterrupt($thread);
            }
        }
    }

    /**
     * Acquires in exclusive mode, aborting if interrupted.
     * Implemented by first checking interrupt status, then invoking
     * at least once {@link #tryAcquire}, returning on
     * success.  Otherwise the thread is queued, possibly repeatedly
     * blocking and unblocking, invoking {@link #tryAcquire}
     * until success or the thread is interrupted.  This method can be
     * used to implement method {@link Lock#lockInterruptibly}.
     *
     * @param arg the acquire argument.  This value is conveyed to
     *        {@link #tryAcquire} but is otherwise uninterpreted and
     *        can represent anything you like.
     * @throws InterruptedException if the current thread is interrupted
     */
    public function acquireInterruptibly(?ThreadInterface $thread = null, int $arg = 0): void
    {
        if ($thread->isInterrupted())
            throw new \Exception("Interrupted");
        if (!$this->tryAcquire($thread, $arg)) {
            $this->doAcquireInterruptibly($thread, $arg);
        }
    }

    /**
     * Attempts to acquire in exclusive mode, aborting if interrupted,
     * and failing if the given timeout elapses.  Implemented by first
     * checking interrupt status, then invoking at least once {@link
     * #tryAcquire}, returning on success.  Otherwise, the thread is
     * queued, possibly repeatedly blocking and unblocking, invoking
     * {@link #tryAcquire} until success or the thread is interrupted
     * or the timeout elapses.  This method can be used to implement
     * method {@link Lock#tryLock(long, TimeUnit)}.
     *
     * @param arg the acquire argument.  This value is conveyed to
     *        {@link #tryAcquire} but is otherwise uninterpreted and
     *        can represent anything you like.
     * @param nanosTimeout the maximum number of nanoseconds to wait
     * @return {@code true} if acquired; {@code false} if timed out
     * @throws InterruptedException if the current thread is interrupted
     */
    public function tryAcquireNanos(?ThreadInterface $thread = null, int $arg = 0, int $nanosTimeout = 0): bool
    {
        if ($thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        return $this->tryAcquire($thread, $arg) ||
            $this->doAcquireNanos($thread, $arg, $nanosTimeout);
    }

    /**
     * Releases in exclusive mode.  Implemented by unblocking one or
     * more threads if {@link #tryRelease} returns true.
     * This method can be used to implement method {@link Lock#unlock}.
     *
     * @param arg the release argument.  This value is conveyed to
     *        {@link #tryRelease} but is otherwise uninterpreted and
     *        can represent anything you like.
     * @return the value returned from {@link #tryRelease}
     */
    public function release(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        if ($this->tryRelease($thread, $arg)) {
            $h = $this->head;
            $hData = $h->get() !== -1 ? $this->queue->get((string) $h->get()) : false;
            if ($hData !== false && $hData['waitStatus'] !== 0) {
                $this->unparkSuccessor($h->get());                
            }
            return true;
        }
        return false;
    }

    /**
     * Acquires in shared mode, ignoring interrupts.  Implemented by
     * first invoking at least once {@link #tryAcquireShared},
     * returning on success.  Otherwise the thread is queued, possibly
     * repeatedly blocking and unblocking, invoking {@link
     * #tryAcquireShared} until success.
     *
     * @param arg the acquire argument.  This value is conveyed to
     *        {@link #tryAcquireShared} but is otherwise uninterpreted
     *        and can represent anything you like.
     */
    public function acquireShared(?ThreadInterface $thread = null, int $arg = 0): void
    {
        if ($this->tryAcquireShared($thread, $arg) < 0) {
            $this->doAcquireShared($thread, $arg);
        }
    }

    /**
     * Acquires in shared mode, aborting if interrupted.  Implemented
     * by first checking interrupt status, then invoking at least once
     * {@link #tryAcquireShared}, returning on success.  Otherwise the
     * thread is queued, possibly repeatedly blocking and unblocking,
     * invoking {@link #tryAcquireShared} until success or the thread
     * is interrupted.
     * @param arg the acquire argument.
     * This value is conveyed to {@link #tryAcquireShared} but is
     * otherwise uninterpreted and can represent anything
     * you like.
     * @throws InterruptedException if the current thread is interrupted
     */
    public function acquireSharedInterruptibly(?ThreadInterface $thread = null, int $arg = 0): void
    {
        if ($thread !== null && $thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        if ($this->tryAcquireShared($thread, $arg) < 0) {
            $this->doAcquireSharedInterruptibly($thread, $arg);
        }
    }

    /**
     * Attempts to acquire in shared mode, aborting if interrupted, and
     * failing if the given timeout elapses.  Implemented by first
     * checking interrupt status, then invoking at least once {@link
     * #tryAcquireShared}, returning on success.  Otherwise, the
     * thread is queued, possibly repeatedly blocking and unblocking,
     * invoking {@link #tryAcquireShared} until success or the thread
     * is interrupted or the timeout elapses.
     *
     * @param arg the acquire argument.  This value is conveyed to
     *        {@link #tryAcquireShared} but is otherwise uninterpreted
     *        and can represent anything you like.
     * @param nanosTimeout the maximum number of nanoseconds to wait
     * @return {@code true} if acquired; {@code false} if timed out
     * @throws InterruptedException if the current thread is interrupted
     */
    public function tryAcquireSharedNanos(?ThreadInterface $thread = null, int $arg = 0, int $nanosTimeout = 0): bool
    {
        if ($thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        return $this->tryAcquireShared($thread, $arg) >= 0 ||
            $this->doAcquireSharedNanos($thread, $arg, $nanosTimeout);
    }

    /**
     * Releases in shared mode.  Implemented by unblocking one or more
     * threads if {@link #tryReleaseShared} returns true.
     *
     * @param arg the release argument.  This value is conveyed to
     *        {@link #tryReleaseShared} but is otherwise uninterpreted
     *        and can represent anything you like.
     * @return the value returned from {@link #tryReleaseShared}
     */
    public function releaseShared(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        if ($this->tryReleaseShared($thread, $arg)) {
            $this->doReleaseShared();
            return true;
        }
        return false;
    }

    // Queue inspection methods

    /**
     * Queries whether any threads are waiting to acquire. Note that
     * because cancellations due to interrupts and timeouts may occur
     * at any time, a {@code true} return does not guarantee that any
     * other thread will ever acquire.
     *
     * <p>In this implementation, this operation returns in
     * constant time.
     *
     * @return {@code true} if there may be other threads waiting to acquire
     */
    public function hasQueuedThreads(): bool
    {
        return $this->head->get() != $this->tail->get();
    }

    /**
     * Queries whether any threads have ever contended to acquire this
     * synchronizer; that is if an acquire method has ever blocked.
     *
     * <p>In this implementation, this operation returns in
     * constant time.
     *
     * @return {@code true} if there has ever been contention
     */
    public function hasContended(): bool
    {
        return $this->head->get() != -1;
    }

    /**
     * Returns the first (longest-waiting) thread in the queue, or
     * {@code null} if no threads are currently queued.
     *
     * <p>In this implementation, this operation normally returns in
     * constant time, but may iterate upon contention if other threads are
     * concurrently modifying the queue.
     *
     * @return the first (longest-waiting) thread in the queue, or
     *         {@code null} if no threads are currently queued
     */
    public function getFirstQueuedThread(): ?int
    {
        // handle only fast path, else relay
        return ($this->head->get() == $this->tail->get()) ? null : $this->fullGetFirstQueuedThread();
    }

    /**
     * Version of getFirstQueuedThread called when fastpath fails
     */
    private function fullGetFirstQueuedThread(): ?ThreadInterface
    {
        /*
         * The first node is normally head.next. Try to get its
         * thread field, ensuring consistent reads: If thread
         * field is nulled out or s.prev is no longer head, then
         * some other thread(s) concurrently performed setHead in
         * between some of our reads. We try this twice before
         * resorting to traversal.
         */
        if ((($h = $this->head)->get() !== -1 && ($hData = $this->queue->get((string) $h->get())) !== false && $hData['next'] !== 0 && ($nData = $this->queue->get((string) $hData['next'])) !== false && $nData['prev'] == $this->head->get() && $nData['thread'] !== 0) || (($h = $this->head)->get() !== -1 && ($hData = $this->queue->get((string) $h->get())) !== false && $hData['next'] !== 0 && ($nData = $this->queue->get((string) $hData['next'])) !== false && $nData['prev'] == $this->head->get() && $nData['pid'] !== 0)) {
            return $nData['pid'];
        }

        /*
         * Head's next field might not have been set yet, or may have
         * been unset after setHead. So we must check to see if tail
         * is actually first node. If not, we continue on, safely
         * traversing from tail back to head to find first,
         * guaranteeing termination.
         */
        if ($this->tail->get() !== -1) {
            $t = $this->tail->get();
            $firstThread = null;
            while ($t !== -1 && $t != $this->head->get()) {
                $tData = $this->queue->get((string) $t);
                $tt = $tData['pid'];
                if ($tt !== 0) {
                    $firstThread = $tt;
                }
                $t = $tData['prev'];
            }
        }
        return $firstThread;
    }

    /**
     * Returns true if the given thread is currently queued.
     *
     * <p>This implementation traverses the queue to determine
     * presence of the given thread.
     *
     * @param thread the thread
     * @return {@code true} if the given thread is on the queue
     * @throws NullPointerException if the thread is null
     */
    public function isQueued(?ThreadInterface $thread = null): bool
    {
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        if ($this->tail->get() !== -1) {
            for ($p = $this->tail->get(); $p !== 0; ) {
                $pData = $this->queue->get((string) $p);
                if ($pData['pid'] == $pid) {
                    return true;
                }
                $p = $pData['prev'];
            }
        }
        return false;
    }

    /**
     * Returns {@code true} if the apparent first queued thread, if one
     * exists, is waiting in exclusive mode.  If this method returns
     * {@code true}, and the current thread is attempting to acquire in
     * shared mode (that is, this method is invoked from {@link
     * #tryAcquireShared}) then it is guaranteed that the current thread
     * is not the first queued thread.  Used only as a heuristic in
     * ReentrantReadWriteLock.
     */
    /*public function apparentlyFirstQueuedIsExclusive(): bool
    {
        $h = null;
        $s = null;
        return ($h = $this->head) != null &&
            ($s = $h->next)  != null &&
            !$s->isShared()         &&
            $s->thread != null;
    }*/

    /**
     * Queries whether any threads have been waiting to acquire longer
     * than the current thread.
     *
     * <p>An invocation of this method is equivalent to (but may be
     * more efficient than):
     *  <pre> {@code
     * getFirstQueuedThread() != Thread.currentThread() &&
     * hasQueuedThreads()}</pre>
     *
     * <p>Note that because cancellations due to interrupts and
     * timeouts may occur at any time, a {@code true} return does not
     * guarantee that some other thread will acquire before the current
     * thread.  Likewise, it is possible for another thread to win a
     * race to enqueue after this method has returned {@code false},
     * due to the queue being empty.
     *
     * <p>This method is designed to be used by a fair synchronizer to
     * avoid <a href="AbstractQueuedSynchronizer#barging">barging</a>.
     * Such a synchronizer's {@link #tryAcquire} method should return
     * {@code false}, and its {@link #tryAcquireShared} method should
     * return a negative value, if this method returns {@code true}
     * (unless this is a reentrant acquire).  For example, the {@code
     * tryAcquire} method for a fair, reentrant, exclusive mode
     * synchronizer might look like this:
     *
     *  <pre> {@code
     * protected boolean tryAcquire(int arg) {
     *   if (isHeldExclusively()) {
     *     // A reentrant acquire; increment hold count
     *     return true;
     *   } else if (hasQueuedPredecessors()) {
     *     return false;
     *   } else {
     *     // try to acquire normally
     *   }
     * }}</pre>
     *
     * @return {@code true} if there is a queued thread preceding the
     *         current thread, and {@code false} if the current thread
     *         is at the head of the queue or the queue is empty
     * @since 1.7
     */
    public function hasQueuedPredecessors(?ThreadInterface $thread = null): bool
    {
        // The correctness of this depends on head being initialized
        // before tail and on head.next being accurate if the current
        // thread is first in queue.
        $t = $this->tail; // Read fields in reverse initialization order
        $h = $this->head;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        return $h->get() != $t->get() &&
              (($hData = $this->queue->get((string) $h->get())) !== false) && 
               (
                    ($s = $hData['next']) === 0 || 
                    ( 
                        (($sData = $this->queue->get((string) $s)) !== false) &&
                        $sData['pid'] !== $pid
                    )
                );
    }


    // Instrumentation and monitoring methods

    /**
     * Returns an estimate of the number of threads waiting to
     * acquire.  The value is only an estimate because the number of
     * threads may change dynamically while this method traverses
     * internal data structures.  This method is designed for use in
     * monitoring system state, not for synchronization
     * control.
     *
     * @return the estimated number of threads waiting to acquire
     */
    public function getQueueLength(): int
    {
        $n = 0;
        if ($this->tail->get !== -1) {
            for ($p = $this->tail->get(); $p !== 0;) {
                $pData = $this->queue->get((string) $p);
                if ($pData['pid'] !== 0) {
                    $n += 1;
                }
                $p = $pData['prev'];
            }
        }
        return $n;
    }

    /**
     * Returns a collection containing threads that may be waiting to
     * acquire.  Because the actual set of threads may change
     * dynamically while constructing this result, the returned
     * collection is only a best-effort estimate.  The elements of the
     * returned collection are in no particular order.  This method is
     * designed to facilitate construction of subclasses that provide
     * more extensive monitoring facilities.
     *
     * @return the collection of threads
     */
    public function getQueuedThreads(): array
    {
        $list = [];
        if ($this->tail->get !== -1) {
            for ($p = $this->tail->get(); $p !== 0;) {
                $pData = $this->queue->get((string) $p);
                if ($pData['pid'] !== 0) {
                    $list[] = $pData['pid'];
                }
                $p = $pData['prev'];
            }
        }
        return $list;
    }

    /**
     * Returns a collection containing threads that may be waiting to
     * acquire in exclusive mode. This has the same properties
     * as {@link #getQueuedThreads} except that it only returns
     * those threads waiting due to an exclusive acquire.
     *
     * @return the collection of threads
     */
    public function getExclusiveQueuedThreads(): array
    {
        $list = [];
        if ($this->tail->get() !== -1) {
            for ($p = $this->tail->get(); $p !== 0;) {
                $pData = $this->queue->get((string) $p);
                $nData = $this->queue->get((string) $pData['nextWaiter']);
                if ($pData['pid'] !== 0 && !($nData !== false && $nData['pid'] == 0 && $nData['waitStatus'] == 0 && $nData['nextWaiter'] == 0)) {
                    $list[] = $pData['pid'];
                }
                $p = $pData['prev'];
            }
        }
        return $list;
    }

    /**
     * Returns a collection containing threads that may be waiting to
     * acquire in shared mode. This has the same properties
     * as {@link #getQueuedThreads} except that it only returns
     * those threads waiting due to a shared acquire.
     *
     * @return the collection of threads
     */
    public function getSharedQueuedThreads(): array
    {
        $list = [];
        if ($this->tail->get() !== -1) {
            for ($p = $this->tail->get(); $p !== 0;) {
                $pData = $this->queue->get((string) $p);
                $nData = $this->queue->get((string) $pData['nextWaiter']);
                if ($pData['pid'] !== 0 && $nData !== false && $nData['pid'] == 0 && $nData['waitStatus'] == 0  && $nData['nextWaiter'] == 0) {
                    $list[] = $pData['pid'];
                }
                $p = $pData['prev'];
            }
        }
        return $list;
    }

    /**
     * Returns a string identifying this synchronizer, as well as its state.
     * The state, in brackets, includes the String {@code "State ="}
     * followed by the current value of {@link #getState}, and either
     * {@code "nonempty"} or {@code "empty"} depending on whether the
     * queue is empty.
     *
     * @return a string identifying this synchronizer, as well as its state
     */
    public function __toString(): string
    {
        $s = $this->getState();
        $q  = $this->hasQueuedThreads() ? "non" : "";
        return /*parent::__toString() .*/ "[State = " . $s . ", " . $q . "empty queue]";
    }


    // Internal support methods for Conditions

    /**
     * Returns true if a node, always one that was initially placed on
     * a condition queue, is now waiting to reacquire on sync queue.
     * @param thread the thread
     * @return true if is reacquiring
     */
    public function isOnSyncQueue(int $node): bool
    {
        //When Node() is predicessor, then $nData['prev'] === 0, but it is not null
        $nData = $this->queue->get((string) $node);
        if ($nData === false || $nData['waitStatus'] == Node::CONDITION || $nData['prev'] === -1) {
            return false;
        }
        if ($nData['next'] !== -1) {// If has successor, it must be on queue
            return true;
        }
        /*
         * node.prev can be non-null, but not yet on queue because
         * the CAS to place it on queue can fail. So we have to
         * traverse from tail to make sure it actually made it.  It
         * will always be near the tail in calls to this method, and
         * unless the CAS failed (which is unlikely), it will be
         * there, so we hardly ever traverse much.
         */
        return $this->findNodeFromTail($nData);
    }

    /**
     * Returns true if node is on sync queue by searching backwards from tail.
     * Called only when needed by isOnSyncQueue.
     * @return true if present
     */
    private function findNodeFromTail(array $node): bool
    {
        if ($this->tail->get() !== -1) {
            $t = $this->tail->get();
            for (;;) {
                if ($t === $node['pid']) {
                    return true;
                }
                if ($t === -1) {
                    return false;
                }
                $tData = $this->queue->get((string) $t);
                $t = $tData['prev'];
            }
        }
        return false;
    }

    /**
     * Transfers a node from a condition queue onto sync queue.
     * Returns true if successful.
     * @param node the node
     * @return true if successfully transferred (else the node was
     * cancelled before signal)
     */
    public function transferForSignal(array $node): bool
    {
        /*
         * If cannot change waitStatus, the node has been cancelled.
         */
        if (!$this->compareAndSetWaitStatus($node, Node::CONDITION, 0)) {
            return false;
        }

        /*
         * Splice onto queue and try to set waitStatus of predecessor to
         * indicate that thread is (probably) waiting. If cancelled or
         * attempt to set waitStatus fails, wake up to resync (in which
         * case the waitStatus can be transiently and harmlessly wrong).
         */        
        $p = $this->enq($node);
        $pData = $this->queue->get((string) $p);
        if ($pData !== false) {
            $ws = $pData['waitStatus'];
            if (($ws !== false && $ws > 0) || !$this->compareAndSetWaitStatus($pData, $ws, Node::SIGNAL)) {
                LockSupport::unpark($node['pid']);
            }
        }
        return true;
    }

    /**
     * Transfers node, if necessary, to sync queue after a cancelled wait.
     * Returns true if thread was cancelled before being signalled.
     *
     * @param node the node
     * @return true if cancelled before the node was signalled
     */
    public function transferAfterCancelledWait(array $node): bool
    {
        if ($this->compareAndSetWaitStatus($node, Node::CONDITION, 0)) {
            $this->enq($node);
            return true;
        }
        /*
         * If we lost out to a signal(), then we can't proceed
         * until it finishes its enq().  Cancelling during an
         * incomplete transfer is both rare and transient, so just
         * spin.
         */
        while (!$this->isOnSyncQueue($node['pid'])) {
            //Thread.yield();
            usleep(1);
        }
        return false;
    }

    /**
     * Invokes release with current state value; returns saved state.
     * Cancels node and throws exception on failure.
     * @param thread to be released
     * @return previous sync state
     */
    public function fullyRelease(?ThreadInterface $thread = null): int
    {
        $failed = true;
        $pid = $thread !== null ? $thread->getPid() : getmypid();
        try {
            $savedState = $this->getState();
            if ($this->release($thread, $savedState)) {
                $failed = false;
                return $savedState;
            } else {
                throw new \Exception("Illegal monitor state");
            }
        } finally {
            if ($failed) {
                $nodeData = $this->queue->get((string) $pid);
                $nodeData['waitStatus'] = Node::CANCELLED;
            }
        }
    }

    // Instrumentation methods for conditions

    /**
     * Queries whether the given ConditionObject
     * uses this synchronizer as its lock.
     *
     * @param condition the condition
     * @return {@code true} if owned
     * @throws NullPointerException if the condition is null
     */
    public function owns(ConditionObject $condition): bool
    {
        return $condition->isOwnedBy($this);
    }

    /**
     * Queries whether any threads are waiting on the given condition
     * associated with this synchronizer. Note that because timeouts
     * and interrupts may occur at any time, a {@code true} return
     * does not guarantee that a future {@code signal} will awaken
     * any threads.  This method is designed primarily for use in
     * monitoring of the system state.
     *
     * @param condition the condition
     * @return {@code true} if there are any waiting threads
     * @throws IllegalMonitorStateException if exclusive synchronization
     *         is not held
     * @throws IllegalArgumentException if the given condition is
     *         not associated with this synchronizer
     * @throws NullPointerException if the condition is null
     */
    public function hasWaiters(ConditionObject $condition): bool
    {
        if (!$this->owns($condition)) {
            throw new \Exception("Not owner");
        }
        return $condition->hasWaiters();
    }

    /**
     * Returns an estimate of the number of threads waiting on the
     * given condition associated with this synchronizer. Note that
     * because timeouts and interrupts may occur at any time, the
     * estimate serves only as an upper bound on the actual number of
     * waiters.  This method is designed for use in monitoring of the
     * system state, not for synchronization control.
     *
     * @param condition the condition
     * @return the estimated number of waiting threads
     * @throws IllegalMonitorStateException if exclusive synchronization
     *         is not held
     * @throws IllegalArgumentException if the given condition is
     *         not associated with this synchronizer
     * @throws NullPointerException if the condition is null
     */
    public function getWaitQueueLength(ConditionObject $condition): int
    {
        if (!$this->owns($condition)) {
            throw new \Exception("Not owner");
        }
        return $condition->getWaitQueueLength();
    }

    /**
     * Returns a collection containing those threads that may be
     * waiting on the given condition associated with this
     * synchronizer.  Because the actual set of threads may change
     * dynamically while constructing this result, the returned
     * collection is only a best-effort estimate. The elements of the
     * returned collection are in no particular order.
     *
     * @param condition the condition
     * @return the collection of threads
     * @throws IllegalMonitorStateException if exclusive synchronization
     *         is not held
     * @throws IllegalArgumentException if the given condition is
     *         not associated with this synchronizer
     * @throws NullPointerException if the condition is null
     */
    public function getWaitingThreads(ConditionObject $condition): array
    {
        if (!$this->owns($condition)) {
            throw new \Exception("Not owner");
        }
        return $condition->getWaitingThreads();
    }

    private function initHead(): bool
    {
        if ($this->head->get() === -1) {
            $this->head->set(0);
            return true;
        }
        return false;
    }

    private function compareAndSetTail(int $expect, int $update): bool
    {
        if ($this->tail->get() == $expect) {
            $this->tail->set($update);
            return true;
        }
        return false;
    }

    private function compareAndSetWaitStatus(array &$node, int $expect, int $update): bool
    {
        if ($node['waitStatus'] == $expect) {
            $node['waitStatus'] = $update;
            $this->queue->set((string) $node['pid'], $node);
            return true;
        }
        return false;
    }

    private function compareAndSetNext(int $node, int $expect, ?int $update): bool
    {
        $nodeData = $this->queue->get((string) $node);        
        if ($nodeData !== false && $nodeData['next'] == $expect) {
            if ($update == null) {
                $nodeData['next'] = 0;   
            } else {
                $nodeData['next'] = $update;                
            }
            $this->queue->set((string) $node, $nodeData);
            return true;
        }
        return false;
    }
}