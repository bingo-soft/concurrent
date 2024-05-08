<?php

namespace Concurrent\Lock;

use Concurrent\{
    ThreadInterface,
    TimeUnit
};
use Concurrent\Executor\ForkJoinPool;
use Concurrent\Lock\LockSupport;

class ConditionObject implements ConditionInterface
{
    /** First node of condition queue. */
    public $firstWaiter;
    /** Last node of condition queue. */
    public $lastWaiter;

    public $synchronizer;

    //Condition queue
    public $queue;

    private const OOME_COND_WAIT_DELAY = 10_000_000; // 10 m

    private const DELETE_THRESHOLD = 50;

    private $nodeLock;

    /**
     * Creates a new {@code ConditionObject} instance.
     */
    public function __construct(?SynchronizerInterface $synchronizer)
    {
        $this->synchronizer = $synchronizer;
        $this->synchronizer->addCondition($this);
        $this->firstWaiter = new \Swoole\Atomic\Long(-1);
        $this->lastWaiter = new \Swoole\Atomic\Long(-1);

        $queue = new \Swoole\Table(2048);
        $queue->column('id', \Swoole\Table::TYPE_INT);
        $queue->column('next', \Swoole\Table::TYPE_INT);
        $queue->column('prev', \Swoole\Table::TYPE_INT);
        $queue->column('waiter', \Swoole\Table::TYPE_INT);
        $queue->column('nextWaiter', \Swoole\Table::TYPE_INT);
        $queue->column('status', \Swoole\Table::TYPE_INT);
        //0 - exclusive, 1 - shared
        $queue->column('mode', \Swoole\Table::TYPE_INT, 2);
        $queue->column('release', \Swoole\Table::TYPE_INT);
        $queue->column('version', \Swoole\Table::TYPE_INT);
        $queue->create();
        $this->queue = $queue;

        $this->nodeLock = new \Swoole\Atomic\Long(-1);
    }

    //shared with AQS
    public function getAndUnsetStatus(array &$node, int $v, bool $sync = true): int
    {
        $status = $node['status'];
        $node['status'] = $status & (~$v);
        $this->upsertNodeAtomically($node['id'], ['status' => $node['status']], $sync);        
        return $status;
    }

    private function upsertNodeAtomically(int $id, array $values, bool $sync = true, bool $updateIfExists = false): void
    {
        if ($updateIfExists && !$this->queue->exists((string) $id)) {
            return;
        }
        try {
            $retry = false;
            while (true) {
                $i = 1;
                while ($this->nodeLock->get() !== -1  && !(($op = $this->synchronizer->operationLock->get()) === -1 || $op === getmypid())) {
                    usleep(1);
                    if ($i++ % 10000 === 0) {
                        fwrite(STDERR, getmypid() . ": updating condition node takes too much wait cycles [$i]!\n");
                    }
                }
                if ($this->nodeLock->cmpset(-1, $id) || $retry) {
                    $retry = false; 
                    if ($newNode !== null && !$this->queue->exists((string) $id)) {
                        foreach ($values as $key => $value) {
                            $newNode[$key] = $value;
                        }
                        $this->queue->set($id, $newNode);
                        if ($sync) {               
                            $this->synchronizer->syncExternalNode($id, $values);
                        }
                    } else {    
                        $newNode = $this->queue->get((string) $id);
                        foreach ($values as $key => $value) {
                            $newNode[$key] = $value;
                        }
                        $newNode['version'] = $newNode['version'] + 1;

                        $this->queue->set($id, $newNode);
                        if ($sync) {
                            $this->synchronizer->syncExternalNode($id, $values);
                        }
                    }
         
                    $newNode = $this->queue->get((string) $id);
                    foreach ($values as $key => $value) {
                        if ($newNode[$key] !== $value) {
                            $retry = true;
                            continue 2;
                        }
                    }
                    break;
                }
            }
        } finally {
            $this->nodeLock->cmpset($id, -1);
        }
    }
    
    public function clearStatus(array &$node): void
    {
        $node['status'] = 0;
        $this->upsertNodeAtomically($node['id'], ['status' => 0]);
    }

    public function setStatusRelaxed(array &$node, int $s): void
    {
        $node['status'] = $s;
        $this->upsertNodeAtomically($node['id'], ['status' => $s]);
    }

    public function syncExternalNode(int $id, array $values): void
    {
        $this->upsertNodeAtomically($id, $values, false, true);
    }

    public function isReleasable(array $node): bool
    {
        return $this->queue->get($node['id'], 'status') <= 1; //Thread.currentThread().isInterrupted();
    }

    public function block(array $node): bool
    {
        while (!$this->isReleasable($node)) {
            LockSupport::park($node['waiter']);
        }
        return true;
    }

    /**
     * Removes and transfers one or all waiters to sync queue.
     */
    private function doSignal(int $first, bool $all = false): void
    {
        while ($first !== -1) {
            $fData = $this->queue->get((string) $first);
            $next = $fData['nextWaiter'];
            if ($next === -1) {
                $this->firstWaiter->set(-1);
                $this->lastWaiter->set(-1);
            }

            if (($this->getAndUnsetStatus($fData, AbstractQueuedSynchronizer::COND, false) & AbstractQueuedSynchronizer::COND) !== 0) {
                $this->synchronizer->enqueue($fData);         
                if (!$all) {
                    break;
                }
            }
            $first = $next;
        }
    }

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
        $first = $this->firstWaiter->get();
        if (!$this->synchronizer->isHeldExclusively($thread)) {
            throw new \Exception("Illegal monitor state");
        } elseif ($first !== -1) {    
            $this->doSignal($first, false);
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
        $first = $this->firstWaiter->get();
        if (!$this->synchronizer->isHeldExclusively($thread)) {
            throw new \Exception("Illegal monitor state");
        } elseif ($first !== -1) {
            $this->doSignal($first, true);
        }
    }

    /**
     * Adds node to condition list and releases lock.
     *
     * @param node the node
     * @return savedState to reacquire after wait
     */
    private function enableWait(?ThreadInterface $thread = null, array &$node): int
    {
        if ($this->synchronizer->isHeldExclusively($thread)) {
            $node['waiter'] = getmypid();
            $this->upsertNodeAtomically($node['id'], ['waiter' => $node['waiter']]);

            $this->setStatusRelaxed($node, AbstractQueuedSynchronizer::COND | AbstractQueuedSynchronizer::WAITING);
            $last = $this->lastWaiter->get();
            if ($last === -1) {
                $this->firstWaiter->set($node['id']);
            } else {
                $lData = $this->queue->get((string) $last);
                $lData['nextWaiter'] = $node['id'];                
                $this->upsertNodeAtomically($last, ['nextWaiter' => $node['id']]);
            }
            $this->lastWaiter->set($node['id']);            
            $savedState = $this->synchronizer->getState();

            if ($this->synchronizer->release($thread, $savedState)) {
                return $savedState;
            }
        }
        $node['status'] = AbstractQueuedSynchronizer::CANCELLED; // lock not held or inconsistent
        $this->upsertNodeAtomically($node['id'], ['status' => $node['status']]);
        throw new \Exception("Illegal monitor state");
    }

    /**
     * Returns true if a node that was initially placed on a condition
     * queue is now ready to reacquire on sync queue.
     * @param node the node
     * @return true if is reacquiring
     */
    private function canReacquire(?array $node): bool
    {
        // check links, not status to avoid enqueue race
        // traverse unless known to be bidirectionally linked
        $ret = $node !== null && ($nData = $this->queue->get((string) $node['id'])) !== false && ($p = $nData['prev']) !== -1 && ((($pData = $this->queue->get((string) $p)) !== false && $pData['next'] === $node['id']) || $this->synchronizer->isEnqueued($node));
        return $ret;
    }

    /**
     * Unlinks the given node and other non-waiting nodes from
     * condition queue unless already unlinked.
     */
    private function unlinkCancelledWaiters(?array $node = null): void
    {
        if (($node === null) || (($nData = $this->queue->get((string) $node['id'])) !== false && $node['nextWaiter'] !== -1) || $node['id'] === $this->lastWaiter->get()) {
            $w = $this->firstWaiter->get();
            $trail = null;
            while ($w !== -1) {
                $wData = $this->queue->get((string) $w);
                $next = $wData['nextWaiter'];
                if (($wData['status'] & AbstractQueuedSynchronizer::COND) === 0) {
                    $wData['nextWaiter'] = -1;
                    $this->upsertNodeAtomically($w, ['nextWaiter' => -1]);
                    //sync
                    $shouldBreak = false;
                    if ($trail === null) {
                        $this->firstWaiter->set($next);
                        if ($next === -1) {
                            $shouldBreak = true;
                        }
                    } else {
                        $trailData = $this->queue->get((string) $trail);
                        $trailData['nextWaiter'] = $next;
                        $this->upsertNodeAtomically($trail, ['nextWaiter' => $next]);
                    }
                    if ($next === -1) {
                        $this->lastWaiter->set($trail ?? -1);
                    }
                } else {
                    $trail = $w;
                }
                $w = $next;
            }
        }
    }

    /**
     * Constructs objects needed for condition wait. On OOME,
     * releases lock, sleeps, reacquires, and returns null.
     */
    private function newConditionNode(?ThreadInterface $thread = null): ?array
    {
        $savedState = 0;
        $pid = $thread !== null ? $thread->pid: getmypid();

        if ($this->synchronizer->tryInitializeHead() !== null) {
            $newNode = ['id' => AbstractQueuedSynchronizer::$nodeCounter->add(), 'next' => -1, 'prev' => -1, 'waiter' => $pid, 'nextWaiter' => -1, 'status' => 0, 'release' => AbstractQueuedSynchronizer::$releaseCounter->get(), 'version' => 0];
            $this->queue->set((string) $newNode['id'], $newNode);
            return $newNode;
        }
        // fall through if encountered OutOfMemoryError
        if (!$this->synchronizer->isHeldExclusively($thread) || !$this->synchronizer->release($thread, $savedState = $this->synchronizer->getState())) {
            throw new \Exception("Illegal monitor state");
        }
        LockSupport::parkNanos($thread, self::OOME_COND_WAIT_DELAY);
        $this->synchronizer->acquireOnOOME($thread, false, $savedState);
        return null;
    }

    private function unlinkOutdatedNodes(int $pid): void
    {

    }

    /**
     * Implements uninterruptible condition wait.
     * <ol>
     * <li>Save lock state returned by {@link #getState}.
     * <li>Invoke {@link #release} with saved state as argument,
     *     throwing IllegalMonitorStateException if it fails.
     * <li>Block until signalled.
     * <li>Reacquire by invoking specialized version of
     *     {@link #acquire} with saved state as argument.
     * </ol>
     */
    public function awaitUninterruptibly(?ThreadInterface $thread = null): void
    {
        $node = $this->newConditionNode($thread);
        if ($node === null) {
            return;
        }
        $savedState = $this->enableWait($thread, $node);
        //LockSupport.setCurrentBlocker(this); // for back-compatibility
        $interrupted = false;
        $rejected = false;
        $blocker = new NodeBlocker($node, $this);
        while (!$this->canReacquire($node)) {
            if ($thread !== null && $thread->isInterrupted()) {
                $interrupted = true;
            } elseif (($this->queue->get((string) $node['id'], 'status') & AbstractQueuedSynchronizer::COND) !== 0) {
                try {
                    if ($rejected) {
                        $blocker->block();
                    } else {
                        ForkJoinPool::managedBlock($blocker);
                    }
                } catch (\Throwable $ex) {
                    $rejected = true;
                }/* catch (InterruptedException ie) {
                    interrupted = true;
                }*/
            } else {
                //Thread.onSpinWait();    // awoke while enqueuing
                usleep(1);
                if ($i++ % 10000 === 0) {
                    fwrite(STDERR, getmypid() . ": await reacquire loop lock takes too much wait cycles [$i]!\n");
                }
            }
            $node = $this->queue->get((string) $node['id']);
        }
        //LockSupport.setCurrentBlocker(null);
        $this->clearStatus($node);
        $this->synchronizer->acquire($thread, $node, $savedState, false, false, false, 0);
        if ($interrupted) {
            //Thread.currentThread().interrupt();
            exit(0);
        }
        //Differ from JDK22
        //$this->queue->del((string) $node['id']);
    }

    /**
     * Implements interruptible condition wait.
     * <ol>
     * <li>If current thread is interrupted, throw InterruptedException.
     * <li>Save lock state returned by {@link #getState}.
     * <li>Invoke {@link #release} with saved state as argument,
     *     throwing IllegalMonitorStateException if it fails.
     * <li>Block until signalled or interrupted.
     * <li>Reacquire by invoking specialized version of
     *     {@link #acquire} with saved state as argument.
     * <li>If interrupted while blocked in step 4, throw InterruptedException.
     * </ol>
     */
    public function await(?ThreadInterface $thread = null, ?int $time = null, ?string $unit = null)
    {
        $pid = $thread !== null ? $thread->pid : getmypid();
        if ($time === null && $unit === null) {
            if ($thread !== null && $thread->isInterrupted()) {
                throw new \Exception("Interrupted");
            }
            $node = $this->newConditionNode($thread);            

            if ($node === null) {
                return;
            }
            $idx = hrtime(true);
            $savedState = $this->enableWait($thread, $node);

            //LockSupport.setCurrentBlocker(this); // for back-compatibility
            $interrupted = false;
            $cancelled = false;
            $rejected = false;
            $blocker = new NodeBlocker($node, $this);
            $i = 1;
            while (!$this->canReacquire($node)) {
                //@TODO. Probably an error, because do not break! Thread may be null!!!
                if ($thread !== null && ($interrupted |= $thread->isInterrupted())) {
                    if ($cancelled = ($this->getAndUnsetStatus($node, AbstractQueuedSynchronizer::COND, false) & AbstractQueuedSynchronizer::COND) !== 0) {
                        break;// else interrupted after signal
                    }
                } elseif (($this->queue->get((string) $node['id'], 'status') & AbstractQueuedSynchronizer::COND) !== 0) {
                    try {
                        if ($rejected) {
                            $blocker->block();
                        } else {
                            ForkJoinPool::managedBlock($blocker);
                        }
                    } catch (\Throwable $ex) {
                        $rejected = true;
                    }/* catch (InterruptedException ie) {
                        interrupted = true;
                    }*/
                } else {
                    //Thread.onSpinWait();    // awoke while enqueuing
                    usleep(1);
                    if ($i++ % 10000 === 0) {
                        fwrite(STDERR, getmypid() . ": await reacquire loop lock takes too much wait cycles [$i]!\n");
                    }
                }
                $node = $this->queue->get((string) $node['id']);
            }
            //LockSupport.setCurrentBlocker(null);
            //may be not needed
            $node = $this->queue->get((string) $node['id']);
            $this->clearStatus($node);
            $this->synchronizer->acquire($thread, $node, $savedState, false, false, false, 0);
            //may be not needed
            $node = $this->queue->get((string) $node['id']);
            if ($interrupted) {
                if ($cancelled) {
                    $this->unlinkCancelledWaiters($node);
                    throw new \Exception("Interrupted");
                }
                //Thread.currentThread().interrupt();
                if ($thread !== null) {
                    $thread->interrupt();
                } else {
                    exit(0);
                }
            }
            //Differ from JDK22
            //$this->queue->del((string) $node['id']);
        } else {
            $nanosTimeout = TimeUnit::toNanos($time, $unit);
            if ($thread !== null && $thread->isInterrupted()) {
                throw new \Exception("Interrupted");
            }
            $node = $this->newConditionNode($thread);
            if ($node === null) {
                return false;
            }
            $savedState = $this->enableWait($thread, $node);
            $nanos = ($nanosTimeout < 0) ? 0 : $nanosTimeout;
            $deadline = hrtime(true) + $nanos;
            $cancelled = false;
            $interrupted = false;
            while (!$this->canReacquire($node)) {
                if (($thread !== null && ($interrupted |= $thread->isInterrupted())) ||
                    ($nanos = $deadline - hrtime(true)) <= 0) {
                    if ($cancelled = ($this->getAndUnsetStatus($node, AbstractQueuedSynchronizer::COND, false) & AbstractQueuedSynchronizer::COND) !== 0) {
                        break;
                    }
                } else {
                    LockSupport::parkNanos($thread, $nanos);
                }
                $node = $this->queue->get((string) $node['id']);
            }
            $this->clearStatus($node);
            $this->synchronizer->acquire($thread, $node, $savedState, false, false, false, 0);
            if ($cancelled) {
                $this->unlinkCancelledWaiters($node);
                if ($interrupted) {
                    throw new \Exception("Interrupted");
                }
            } elseif ($interrupted) {
                if ($thread !== null) {
                    $thread->interrupt();
                } else {
                    exit(0);
                }
            }
            return !$cancelled;
        }
    }

    /**
     * Implements timed condition wait.
     * <ol>
     * <li>If current thread is interrupted, throw InterruptedException.
     * <li>Save lock state returned by {@link #getState}.
     * <li>Invoke {@link #release} with saved state as argument,
     *     throwing IllegalMonitorStateException if it fails.
     * <li>Block until signalled, interrupted, or timed out.
     * <li>Reacquire by invoking specialized version of
     *     {@link #acquire} with saved state as argument.
     * <li>If interrupted while blocked in step 4, throw InterruptedException.
     * </ol>
     */
    public function awaitNanos(?ThreadInterface $thread = null, int $nanosTimeout = 0): int
    {
        if ($thread !== null && $thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        $node = $this->newConditionNode($thread);
        if ($node === null) {
            return $nanosTimeout - self::OOME_COND_WAIT_DELAY;
        }
        $savedState = $this->enableWait($thread, $node);
        $nanos = ($nanosTimeout < 0) ? 0 : $nanosTimeout;
        $deadline = hrtime(true) + $nanos;
        $cancelled = false;
        $interrupted = false;
        while (!$this->canReacquire($node)) {
            if (($thread !== null && $interrupted |= $thread->isInterrupted()) || ($nanos = $deadline - hrtime(true)) <= 0) {
                $status = $this->getAndUnsetStatus($node, AbstractQueuedSynchronizer::COND, false) & AbstractQueuedSynchronizer::COND;
                if ($cancelled = $status !== 0) {
                    break;
                }
            } else {
                LockSupport::parkNanos($thread, $nanos);
            }
            //can be updated in other threads, so get again
            $node = $this->queue->get((string) $node['id']);
        }
        //may be not needed
        $node = $this->queue->get((string) $node['id']);
        $this->clearStatus($node);
        $this->synchronizer->acquire($thread, $node, $savedState, false, false, false, 0); //false, 0
        //may be not needed
        
        $node = $this->queue->get((string) $node['id']);
        if ($cancelled) {
            $this->unlinkCancelledWaiters($node);
            if ($interrupted) {
                throw new \Exception("Interrupted");
            }
        } elseif ($interrupted) {
            if ($thread !== null) {
                $thread->interrupt();
            } else {
                exit(0);
            }
        }

        $remaining = $deadline - hrtime(true); // avoid overflow
        return ($remaining <= $nanosTimeout) ? $remaining : PHP_INT_MIN;
    }

    /**
     * Implements absolute timed condition wait.
     * <ol>
     * <li>If current thread is interrupted, throw InterruptedException.
     * <li>Save lock state returned by {@link #getState}.
     * <li>Invoke {@link #release} with saved state as argument,
     *     throwing IllegalMonitorStateException if it fails.
     * <li>Block until signalled, interrupted, or timed out.
     * <li>Reacquire by invoking specialized version of
     *     {@link #acquire} with saved state as argument.
     * <li>If interrupted while blocked in step 4, throw InterruptedException.
     * <li>If timed out while blocked in step 4, return false, else true.
     * </ol>
     */
    public function awaitUntil(?ThreadInterface $thread = null, \DateTime $deadline = null): bool
    {
        $abstime = $deadline->getTime()->getTimestamp();
        if ($thread !== null && $thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        $node = $this->newConditionNode($thread);
        if ($node === null) {
            return false;
        }
        $savedState = $this->enableWait($thread, $node);
        $cancelled = false;
        $interrupted = false;
        while (!$this->canReacquire($node)) {
            if (($thread !== null && $interrupted |= $thread->isInterrupted()) || time() > $abstime) {
                if ($cancelled = ($this->getAndUnsetStatus($node, AbstractQueuedSynchronizer::COND, false) & AbstractQueuedSynchronizer::COND) !== 0) {
                    break;
                }
            } else {
                //@TODO - implement this method, will throw exception now
                LockSupport::parkUntil($thread, $abstime);
            }
            $node = $this->queue->get((string) $node['id']);
        }
        //may be not needed
        $node = $this->queue->get((string) $node['id']);
        $this->clearStatus($node);
        $this->synchronizer->acquire($thread, $node, $savedState, false, false, false, 0);
        //may be not needed
        $node = $this->queue->get((string) $node['id']);
        if ($cancelled) {
            $this->unlinkCancelledWaiters($node);
            if ($interrupted) {
                throw new \Exception("Interrupted");
            }
        } elseif ($interrupted) {
            if ($thread !== null) {
                $thread->interrupt();
            } else {
                exit(0);
            }
        }
        //Differ from JDK22
        //$this->queue->del((string) $node['id']);
        return !$cancelled;
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
        return $this->synchronizer == $this;
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
            if (($wData['status'] & AbstractQueuedSynchronizer::COND) !== 0) {
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
            if (($wData['status'] & AbstractQueuedSynchronizer::COND) !== 0) {
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
            if (($wData['status'] & AbstractQueuedSynchronizer::COND) !== 0) {
                $t = $wData['waiter'];
                if ($t !== 0 && $t !== -1) {
                    $list[] = $t;
                }
            }
            $w = $wData['nextWaiter'];
        }
        return $list;
    }
}
