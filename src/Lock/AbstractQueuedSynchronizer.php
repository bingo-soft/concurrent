<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;
use Concurrent\Executor\ThreadLocalRandom;

abstract class AbstractQueuedSynchronizer extends AbstractOwnableSynchronizer
{
    /**
     * Head of the wait queue, lazily initialized.  Except for
     * initialization, it is modified only via method setHead.  Note:
     * If head exists, its waitStatus is guaranteed not to be
     * CANCELLED.
     */
    public $head;

    public $headNext;

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
    public $queue;

    // Condition object, that may have references to the same nodes from synchronization queue
    private $conditions = [];

    // Node status bits, also used as argument and return values
    public const WAITING   = 1;          // must be 1
    public const CANCELLED = -2147483648; // must be negative
    public const COND      = 2;          // in a condition wait

    public static $nodeCounter;

    // Used to prevent unlkinked nodes piling. Clear by batches of size CLEAN_UP_BATCH_SIZE
    public static $releaseCounter;
    public static $lastCleanUp; 
    public const CLEAN_UP_BATCH_SIZE = 40;

    private $nodeLock;

    //shared with ConditionObject
    public $operationLock;

    /**
     * Creates a new {@code AbstractQueuedSynchronizer} instance
     * with initial synchronization state of zero.
     */
    public function __construct()
    {
        parent::__construct();
        $this->state = new \Swoole\Atomic\Long(0);

        $this->head = new \Swoole\Atomic\Long(-1);
        $this->tail = new \Swoole\Atomic\Long(-1);

        $queue = new \Swoole\Table(2048);
        $queue->column('id', \Swoole\Table::TYPE_INT);
        $queue->column('next', \Swoole\Table::TYPE_INT);
        $queue->column('prev', \Swoole\Table::TYPE_INT);
        $queue->column('waiter', \Swoole\Table::TYPE_INT);
        //$queue->column('prevWaiter', \Swoole\Table::TYPE_INT);
        $queue->column('nextWaiter', \Swoole\Table::TYPE_INT);
        $queue->column('status', \Swoole\Table::TYPE_INT);
        //0 - exclusive, 1 - shared
        $queue->column('mode', \Swoole\Table::TYPE_INT, 2);
        $queue->column('release', \Swoole\Table::TYPE_INT);
        $queue->column('version', \Swoole\Table::TYPE_INT);
        $queue->create();
        $this->queue = $queue;

        if (self::$nodeCounter === null) {
            self::$nodeCounter = new \Swoole\Atomic\Long(0);

            self::$releaseCounter = new \Swoole\Atomic\Long(0);
            self::$lastCleanUp = new \Swoole\Atomic\Long(0);

            self::$testCounter = new \Swoole\Atomic\Long(0);
        }

        $this->nodeLock = new \Swoole\Atomic\Long(-1);
        $this->operationLock = new \Swoole\Atomic\Long(-1);
    }

    public function addCondition(ConditionInterface $condition): void
    {
        $this->conditions[] = $condition;
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
        return $this->state->cmpset($expect, $update);
    }

    public function casTail($expect, $update): bool
    {
        return $this->tail->cmpset($expect, $update);
    }

    /**
     * Tries to CAS a new dummy node for head.
     * Returns new tail
     */
    public function tryInitializeHead(): int
    {
        for ($h = null;;) {
            if (($t = $this->tail->get()) !== -1) {
                return $t;
            } elseif ($this->head->get() !== -1) {
                //Thread.onSpinWait();
                usleep(1);
            } else {
                if ($h === null) {
                    $h = self::$nodeCounter->add();
                    //Create Dummy node
                    $newNode = ['id' => $h, 'next' => -1, 'prev' => -1, 'waiter' => -1, 'nextWaiter' => -1, 'status' => 0, 'version' => 0];
                    $this->queue->set((string) $h, $newNode);
                }
                if ($this->head->cmpset(-1, $h)) {
                    $this->tail->set($h);
                    return $h;
                }
            }
        }
    }

    public function casPrev(array &$node, int $c, int $v): bool
    {
        if (($node = $this->queue->get((string) $node['id'])) !== false && $node['prev'] === $c) {
            $this->upsertNodeAtomically($node['id'], ['prev' => $v]);            
            return true;
        }
        return false;
    }

    public function casNext(array &$node, int $c, int $v): bool
    {
        if (($node = $this->queue->get((string) $node['id'])) !== false && $node['next'] === $c) {
            $this->upsertNodeAtomically( $node['id'], ['next' => $v]);  
            return true;
        }
        return false;
    }

    public function getAndUnsetStatus(array &$node, int $v): int
    {        
        $status = $this->queue->get((string) $node['id'], 'status');
        $this->upsertNodeAtomically($node['id'], ['status' => $status & (~$v)]);
        return $status;        
    }

    public function syncExternalNode(int $id, array $values): void
    {        
        if ($this->queue->exists((string) $id)) {
            $newNode = $this->queue->get($id);
            foreach ($values as $key => $value) {
                $newNode[$key] = $value;
            }
            $newNode['version'] = $newNode['version'] + 1;
            $this->queue->set($newNode['id'], $newNode);
        }
        //$this->upsertNodeAtomically($id, $values, null, false, true);
    }

    public function syncConditionNode(int $id, array $values): void
    {
        foreach ($this->conditions as $condition) {
            $condition->syncExternalNode($id, $values);  
        }
    }

    /**
     * Enqueues the node unless null. (Currently used only for
     * ConditionNodes; other cases are interleaved with acquires.)
     */
    public function enqueue(?array $node): void
    {
        if ($node !== null) {
            $unpark = false;
            //@TODO. Check this difference with JDK22. We see, that waiter may be -1
            try {
                for (;;) {
                    $i = 1;
                    while ($this->operationLock->get() !== -1) {
                        usleep(1);
                        if ($i++ % 10000 === 0) {
                            fwrite(STDERR, getmypid() . ": node enqueue takes too much wait cycles [$i]!\n");
                        }
                    }
                    if ($this->operationLock->cmpset(-1, getmypid())) {   
                        if (($t = $this->tail->get()) === -1 && ($t = $this->tryInitializeHead()) === null) {
                            $unpark = true;             // wake up to spin on OOME
                            $this->operationLock->cmpset(getmypid(), -1);
                            break;
                        }
                        $this->upsertNodeAtomically($node['id'], ['prev' => $t], $node);

                        if ($this->casTail($t, $node['id'])) {
                            $this->upsertNodeAtomically($t, ['next' => $node['id']]);  
                            $tData = $this->queue->get((string) $t);
                            if ($tData['status'] < 0) { // wake up to clean link
                                $unpark = true;
                            }
                            break;
                        }
                    }
                }
            } finally {
                $this->operationLock->cmpset(getmypid(), -1);
            }
            if ($unpark) {
                LockSupport::unpark($node['waiter']);
            }
        }
    }

    /** Returns true if node is found in traversal from tail */
    public function isEnqueued(array $node): bool
    {
        for ($t = $this->tail->get(); $t !== -1; ) {
            if ($t === $node['id']) {
                return true;
            }
            $tData = $this->queue->get((string) $t);
            $t = $tData['prev'];
        }
        return false;
    }

    /**
     * Wakes up the successor of given node, if one exists, and unsets its
     * WAITING status to avoid park race. This may fail to wake up an
     * eligible thread when one or more have been cancelled, but
     * cancelAcquire ensures liveness.
     */
    public function signalNext(int $h): void
    {
        if ($h !== -1 && ($hData = $this->queue->get((string) $h)) !== false && ($s = $hData['next']) !== -1 && ($sData = $this->queue->get((string) $s)) !== false && $sData['status'] !== 0) {
            $this->getAndUnsetStatus($sData, self::WAITING);                    
            LockSupport::unpark($sData['waiter']);
        }
    }

    /** Wakes up the given node if in shared mode */
    private function signalNextIfShared(int $h): void
    {
        if ($h !== -1 && ($hData = $this->queue->get((string) $h)) !== false && ($s = $hData['next']) !== -1 && ($sData = $this->queue->get((string) $s)) !== false && $sData['mode'] === 1 && $sData['status'] !== 0) {
            $this->getAndUnsetStatus($s, self::WAITING);
            LockSupport::unpark($sData['waiter'], hrtime(true));
        }
    }

    /**
     * Main acquire method, invoked by all exported acquire methods.
     *
     * @param node null unless a reacquiring Condition
     * @param arg the acquire argument
     * @param shared true if shared mode else exclusive
     * @param interruptible if abort and return negative on interrupt
     * @param timed if true use timed waits
     * @param time if timed, the System.nanoTime value to timeout
     * @return positive if acquired, 0 if timed out, negative if interrupted
     */
    public function acquire(?ThreadInterface $thread, array | int &$node = null, ?int $arg = null, bool $shared = false, bool $interruptible = false, bool $timed = false, int $time = 0)
    {
        $pid = $thread !== null ? $thread->pid : getmypid();
        if ($arg === null) {
            $arg = $node;
            if (!$this->tryAcquire($thread, $arg)) {
                $node = null;
                $this->acquire($thread, $node, $arg, false, false, false, 0);
            }
        } else {
            //byte fields, so adjust
            $spins = 0;
            $postSpins = 0;   // retries upon unpark of first thread
            $interrupted = false;
            $first = false;
            $pred = -1;               // predecessor of node when enqueued
            /*
            * Repeatedly:
            *  Check if node now first
            *    if so, ensure head stable, else ensure valid predecessor
            *  if node is first or not yet enqueued, try acquiring
            *  else if queue is not initialized, do so by attaching new header node
            *     resort to spinwait on OOME trying to create node
            *  else if node not yet created, create it
            *     resort to spinwait on OOME trying to create node
            *  else if not yet enqueued, try once to enqueue
            *  else if woken from park, retry (up to postSpins times)
            *  else if WAITING status not set, set and retry
            *  else park and clear WAITING status, and check cancellation
            */
            if ($node !== null && $this->queue->get((string) $node['id']) === false) {
                $this->queue->set((string) $node['id'], $node);
            }
            $j = 1;
            for (;;) {                
                if (!$first && ($pred = ($node === null) ? null :  (($node = $this->queue->get((string) $node['id'])) !== false ? $node['prev'] : null)  ) !== null && $pred !== -1 && !($first = ($this->head->get() === $pred))) {
                    $pData = $this->queue->get((string) $pred);
                    if ($pData['status'] < 0) {
                        $this->cleanQueue($thread);  // predecessor cancelled
                        continue;
                    } elseif ($pData['prev'] === -1) {
                        usleep(1);                   
                        continue;
                    }
                }                
                if ($first || $pred === null || $pred === -1) {
                    $acquired = false;
                    try {              
                        if ($shared) {
                            $acquired = ($this->tryAcquireShared($thread, $arg) >= 0);
                        } else {
                            $acquired = $this->tryAcquire($thread, $arg);
                        }                        
                    } catch (\Throwable $ex) {
                        $this->cancelAcquire($thread, $node, $interrupted, false);
                        throw $ex;
                    }
                    if ($node !== null) {
                        $node = $this->queue->get((string) $node['id']);
                    }
                    if ($acquired) { //removal from synchronization queue
                        if ($first) {                            
                            $this->upsertNodeAtomically($node['id'], ['prev' => -1, 'waiter' => -1]);
                            $this->head->set($node['id']);
                           
                            $pData = $this->queue->get((string) $pred);
                            $pData['next'] = -1;                            
                            $this->syncConditionNode($pred, ['next' => -1]);

                            $this->queue->del((string) $pred);
                            if ($shared) {
                                $this->signalNextIfShared($node['id']);
                            }
                            if ($interrupted && $thread !== null) {
                                $thread->interrupt();
                            }
                        }
                        return 1;
                    }
                }
                if ($node !== null) {
                    $node = $this->queue->get($node['id']);
                }
                if (($t = $this->tail->get()) === -1) {           // initialize queue
                    if ($this->tryInitializeHead() === null) {
                        return $this->acquireOnOOME($thread, $shared, $arg);
                    }
                } elseif ($node === null) { // allocate; retry before enqueue                    
                    if ($shared) {
                        $node = ['id' => self::$nodeCounter->add(), 'next' => -1, 'prev' => -1, 'waiter' => $pid, 'nextWaiter' => -1, 'status' => 0, 'mode' => 1, 'release' => self::$releaseCounter->get(), 'version' => 0];
                    } else {
                        $node = ['id' => self::$nodeCounter->add(), 'next' => -1, 'prev' => -1, 'waiter' => $pid, 'nextWaiter' => -1, 'status' => 0, 'mode' => 0, 'release' => self::$releaseCounter->get(), 'version' => 0];
                    }

                    $this->queue->set((string) $node['id'], $node);
                } elseif ($pred === null || $pred === -1) {          // try to enqueue
                    try {
                        while (true) {
                            $i = 1;
                            while ($this->operationLock->get() !== -1) {
                                usleep(1);
                                if ($i++ % 10000 === 0) {
                                    fwrite(STDERR, getmypid() . ": setting node prev and wait fields takes too much wait cycles [$i]!\n");
                                }
                            }
                            if ($this->operationLock->cmpset(-1, getmypid())) {
                                $this->upsertNodeAtomically($node['id'], ['prev' => $t, 'waiter' => $pid], $node);
                                if (!$this->casTail($t, $node['id'])) {
                                    // back out, set to null .  
                                    $this->upsertNodeAtomically($node['id'], ['prev' => -1]);                  
                                } else {
                                    $this->upsertNodeAtomically($t, ['next' => $node['id']]);                   
                                }
                                break;
                            }
                        }
                    } finally {
                        $this->operationLock->cmpset(getmypid(), -1);
                    }
                    
                } elseif ($first && $spins !== 0) {
                    $spins = ThreadLocalRandom::intToByte(--$spins);                        // reduce unfairness on rewaits
                    usleep(1);
                    if ($j++ % 10000 === 0) {
                        fwrite(STDERR, getmypid() . ": acquiring lock takes too much wait cycles [$j]!\n");
                    }
                } elseif ($node['status'] === 0) {
                    $this->upsertNodeAtomically($node['id'], ['status' => self::WAITING]);
                } else {
                    $postSpins = ThreadLocalRandom::intToByte(($postSpins << 1) | 1);
                    $spins = $postSpins;
                    if (!$timed) {                        
                        LockSupport::park($thread);
                    } elseif (($nanos = $time - hrtime(true)) > 0) {
                        LockSupport::parkNanos($thread, $nanos);
                    } else {
                        break;
                    }
                    //@TODO. Differ from JDK, because node could be changed in another process
                    $node = $this->queue->get((string) $node['id']);
                    $this->upsertNodeAtomically($node['id'], ['status' => 0]);
                    if ($thread !== null && ($interrupted |= $thread->isInterrupted()) && $interruptible) {
                        break;
                    }
                }
            }
            return $this->cancelAcquire($thread, $node, $interrupted, $interruptible);
        }
    }

    private function upsertNodeAtomically(int $id, array $values, ?array $newNode = null, bool $sync = true, bool $updateIfExists = false): void
    {
        if ($updateIfExists && !$this->queue->exists((string) $id)) {
            return;
        }
        try {

            $retry = false;
            while (true) {
                $i = 1;
                while ($this->nodeLock->get() !== -1 && !(($op = $this->operationLock->get()) === -1 || $op === getmypid())) {
                    usleep(1);
                    if ($i++ % 10000 === 0) {
                        fwrite(STDERR, getmypid() . ": updating sync node takes too much wait cycles [$i]!\n");
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
                            $this->syncConditionNode($id, $values);
                        }
                    } else {    
                        $newNode = $this->queue->get((string) $id);
                        foreach ($values as $key => $value) {
                            $newNode[$key] = $value;
                        }
                        $newNode['version'] = $newNode['version'] + 1;

                        $this->queue->set($id, $newNode);
                        if ($sync) {
                            $this->syncConditionNode($id, $values);
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
                continue;
            }
        } finally {
            $this->nodeLock->cmpset($id, -1);
        }
        
    }

    /**
     * Spin-waits with backoff; used only upon OOME failures during acquire.
     */
    public function acquireOnOOME(?ThreadInterface $thread, bool $shared, int $arg): int
    {
        for ($nanos = 1;;) {
            if ($shared ? ($this->tryAcquireShared($thread, $arg) >= 0) : $this->tryAcquire($thread, $arg)) {
                return 1;
            }
            LockSupport::parkNanos($thread, $nanos);               // must use Unsafe park to sleep
            if ($nanos < (1 << 30))  {             // max about 1 second
                $nanos <<= 1;
            }
        }
    }

    /**
     * Possibly repeatedly traverses from tail, unsplicing cancelled
     * nodes until none are found. Unparks nodes that may have been
     * relinked to be next eligible acquirer.
     */
    private function cleanQueue(?ThreadInterface $thread): void
    {
        try {
            for (;;) {
                // restart point
                $i = 1;         
                while ($this->operationLock->get() !== -1) {
                    usleep(1);
                    if ($i++ % 10000 === 0) {
                        fwrite(STDERR, getmypid() . ": waiting operation lock takes too much wait cycles [$i]!\n");
                    }
                }
                if ($this->operationLock->cmpset(-1, getmypid())) {
                    for ($q = $this->tail->get(), $s = null;;) { // (p, q, s) triples
                        if ($q === -1 || (($qData = $this->queue->get((string) $q)) !== false && ($p = $qData['prev']) === -1)) {
                            return;                      // end of list
                        }
                        if ($s === null ? $this->tail->get() !== $q : (($sData = $this->queue->get((string) $s)) !== false && ($sData['prev'] !== $q || $sData['status'] < 0))) {
                            break;                       // inconsistent
                        }
                        $pData = $this->queue->get((string) $p);
                        if ($qData['status'] < 0) {              // cancelled
                            if (($s === null ? $this->casTail($q, $p) : $this->casPrev($sData, $q, $p)) && $qData['prev'] === $p) {
                                $this->casNext($pData, $q, $s ?? -1);         // OK if fails   
                                if ($pData['prev'] === -1) {
                                    $this->signalNext($p);
                                }
                            }
                            break;
                        }
                        if (($n = $pData['next']) !== $q) {         // help finish
                            if ($n !== -1 && $qData['prev'] === $p) {
                                $this->casNext($pData, $n, $q);
                                if ($pData['prev'] === -1) {
                                    $this->signalNext($p);
                                }
                            }
                            break;
                        }
                        $s = $q;
                        $q = $qData['prev'];
                    }
                    break;
                }            
            }
        } finally {
            $this->operationLock->cmpset(getmypid(), -1);
        }
    }

    /**
     * Cancels an ongoing attempt to acquire.
     *
     * @param node the node (may be null if cancelled before enqueuing)
     * @param interrupted true if thread interrupted
     * @param interruptible if should report interruption vs reset
     */
    private function cancelAcquire(?ThreadInterface $thread, ?array &$node, bool $interrupted, bool $interruptible): int
    {
        if ($node !== null && $node !== -1) {
            $this->upsertNodeAtomically($node['id'], ['waiter' => -1, 'next' => -1, 'status' => self::CANCELLED]);
 
            $node = $this->queue->get((string) $node['id']);
            if ($node['prev'] !== -1) {
                $this->cleanQueue($thread);
            }
        }
        if ($interrupted) {
            if ($interruptible) {
                return self::CANCELLED;
            } else {
                if ($thread !== null) {
                    $thread->interrupt();
                } else {
                    exit(0);
                }
            }
        }
        return 0;
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
    public function acquireInterruptibly(?ThreadInterface $thread, int $arg): void
    {
        $node = null;
        if (($thread !== null && $thread->isInterrupted()) ||
            (!$this->tryAcquire($thread, $arg) && $this->acquire($thread, $node, $arg, false, true, false, 0) < 0)) {
            throw new \Exception("Interrupted");
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
    public function tryAcquireNanos(?ThreadInterface $thread, int $arg, int $nanosTimeout): bool
    {
        if ($thread !== null && !$thread->isInterrupted()) {
            if ($this->tryAcquire($thread, $arg)) {
                return true;
            }
            if ($nanosTimeout <= 0) {
                return false;
            }
            $node = null;
            $stat = $this->acquire($thread, $node, $arg, false, true, true, hrtime(true) + $nanosTimeout);
            if ($stat > 0) {
                return true;
            }
            if ($stat === 0) {
                return false;
            }
        }
        throw new \Exception("Interrupted");
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
    public function release(?ThreadInterface $thread, int $arg): bool
    {
        if ($this->tryRelease($thread, $arg)) {
            $this->signalNext($this->head->get());
            $this->cleanUp($thread);
            return true;
        }
        return false;
    }

    //prevent unlinked nodes to pile up in synchronization queue
    private function cleanUp(?ThreadInterface $thread = null): void
    {        
        $release = self::$releaseCounter->get();
        $threshold = $release - self::CLEAN_UP_BATCH_SIZE;

        $pid = $thread !== null ? $thread->pid : getmypid();
        $i = 0;
        foreach ($this->conditions as $condition) {
            $tail = $condition->firstWaiter->get();
            $nodes = [];
            while ($tail !== -1) {
                $tNode = $condition->queue->get((string) $tail);
                $nodes[] = $tail;
                $tail = $tNode['nextWaiter'];                    
            }

            foreach ($condition->queue as $key => $node) {
                if (!in_array($node['id'], $nodes) && $node['release'] <= $threshold && $node['status'] !== 1) {    
                    $condition->queue->del((string) $key);
                }
            }
        }

        $this->queue->rewind();
        while ($this->queue->valid()) {
            $node = $this->queue->current();            
            if ($node['release'] < $threshold && $node['next'] === -1 && $node['prev'] === -1 && $node['status'] === 0 && ($node['waiter'] === $pid || $node['waiter'] === -1) && $this->head->get() !== $node['id']) {
                $key = $this->queue->key();
                $this->queue->del($key);
            }
            $this->queue->next();
        }
        
        self::$releaseCounter->add();       
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
    public function acquireShared(?ThreadInterface $thread, int $arg): void
    {
        if ($this->tryAcquireShared($thread, $arg) < 0) {
            $node = null;
            $this->acquire($thread, $node, $arg, true, false, false, 0);
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
    public function acquireSharedInterruptibly(?ThreadInterface $thread, int $arg): void
    {
        $node = null;
        if (($thread !== null && $thread->isInterrupted()) ||
            ($this->tryAcquireShared($thread, $arg) < 0 &&
             $this->acquire($thread, $node, $arg, true, true, false, 0) < 0)) {
            throw new \Exception("Interrupted");
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
    public function tryAcquireSharedNanos(?ThreadInterface $thread, int $arg, int $nanosTimeout): bool
    {
        if (!($thread !== null && $thread->isInterrupted()) || $thread === null) {
            if ($this->tryAcquireShared($thread, $arg) >= 0) {
                return true;
            }
            if ($nanosTimeout <= 0) {
                return false;
            }
            $node = null;
            $stat = $this->acquire($thread, $node, $arg, true, true, true,
                               hrtime(true) + $nanosTimeout);
            if ($stat > 0) {
                return true;
            }
            if ($stat === 0) {
                return false;
            }
        }
        throw new \Exception("Interrupted");
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
    public function releaseShared(?ThreadInterface $thread, int $arg): bool
    {
        if ($this->tryReleaseShared($thread, $arg)) {
            $this->signalNext($this->head->get());
            return true;
        }
        return false;
    }

    // Queue inspection methods

    private static $testCounter;
    /**
     * Queries whether any threads are waiting to acquire. Note that
     * because cancellations due to interrupts and timeouts may occur
     * at any time, a {@code true} return does not guarantee that any
     * other thread will ever acquire.
     *
     * @return {@code true} if there may be other threads waiting to acquire
     */
    public function hasQueuedThreads(): bool
    {
        for ($p = $this->tail->get(), $h = $this->head->get(); $p !== $h && $p !== -1; ) {
            $pData = $this->queue->get((string) $p);
            if ($pData['status'] >= 0) {
                return true;
            }
            $p = $pData['prev'];
        }
        return false;
    }

    /**
     * Queries whether any threads have ever contended to acquire this
     * synchronizer; that is, if an acquire method has ever blocked.
     *
     * <p>In this implementation, this operation returns in
     * constant time.
     *
     * @return {@code true} if there has ever been contention
     */
    public function hasContended(): bool
    {
        return $this->head->get() !== -1;
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
        $first = null;
        if (($h = $this->head->get()) !== -1 && (($hData = $this->queue->get((string) $h)) !== false && ($s = $hData['next']) === -1 ||
                                   (($sData = $this->queue->get((string) $s)) !== false && (($first = $sData['waiter']) === -1) ||
                                   $sData['prev'] === -1))) {
            // traverse from tail on stale reads
            for ($p = $this->tail->get(); $p !== -1 && ($pData = $this->queue->get((string) $p)) !== false && ($q = $pData['prev']) !== -1; $p = $q) {
                if (($w = $pData['waiter']) !== -1) {
                    $first = $w;
                }
            }                
        }
        return $first;
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
                if ($pData['waiter'] === $pid) {
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
    public function apparentlyFirstQueuedIsExclusive(): bool
    {
        return ($h = $this->head->get()) !== -1 && ($hData = $this->queue->get((string) $h)) !== false && ($s = $hData['next']) !== -1 && ($sData = $this->queue->get((string) $s)) !== false && $sData['mode'] !== 1 && $sData['waiter'] !== -1;
    }

    /**
     * Queries whether any threads have been waiting to acquire longer
     * than the current thread.
     *
     * <p>An invocation of this method is equivalent to (but may be
     * more efficient than):
     * <pre> {@code
     * getFirstQueuedThread() != Thread.currentThread()
     *   && hasQueuedThreads()}</pre>
     *
     * <p>Note that because cancellations due to interrupts and
     * timeouts may occur at any time, a {@code true} return does not
     * guarantee that some other thread will acquire before the current
     * thread.  Likewise, it is possible for another thread to win a
     * race to enqueue after this method has returned {@code false},
     * due to the queue being empty.
     *
     * <p>This method is designed to be used by a fair synchronizer to
     * avoid <a href="AbstractQueuedSynchronizer.html#barging">barging</a>.
     * Such a synchronizer's {@link #tryAcquire} method should return
     * {@code false}, and its {@link #tryAcquireShared} method should
     * return a negative value, if this method returns {@code true}
     * (unless this is a reentrant acquire).  For example, the {@code
     * tryAcquire} method for a fair, reentrant, exclusive mode
     * synchronizer might look like this:
     *
     * <pre> {@code
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
    public function hasQueuedPredecessors(): bool
    {
        $first = null;
        if (($h = $this->head->get()) !== -1 && ($hData = $this->queue->get((string) $h)) !== false && (($s = $hData['next']) === -1 ||
                                   ( ($sData = $this->queue->get((string) $s)) !== false && (($first = $sData['waiter']) === -1 || $sData['prev'] === -1))
                                   )) {
            $firstBefore = $first;
            $first = $this->getFirstQueuedThread(); // retry via getFirstQueuedThread
        }        
        return $first !== null && $first !== -1 && $first !== getmypid();
    }

    // Instrumentation and monitoring methods

    /**
     * Returns an estimate of the number of threads waiting to
     * acquire.  The value is only an estimate because the number of
     * threads may change dynamically while this method traverses
     * internal data structures.  This method is designed for use in
     * monitoring system state, not for synchronization control.
     *
     * @return the estimated number of threads waiting to acquire
     */
    public function getQueueLength(): int
    {
        $n = 0;
        for ($p = $this->tail->get(); $p !== -1; ) {
            $pData = $this->queue->get((string) $p);            
            if ($pData['waiter'] !== -1) {
                $n += 1;
            }
            $p = $pData['prev'];
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
        for ($p = $this->tail->get(); $p !== -1;) {
            $pData = $this->queue->get((string) $p); 
            $t = $pData['waiter'];
            if ($t !== -1 && $t !== 0) {
                $list[] = $t;
            }
            $p = $pData['prev'];
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
        for ($p = $this->tail->get(); $p !== -1;) {
            $pData = $this->queue->get((string) $p); 
            if ($pData['mode'] !== 1) {
                $t = $pData['waiter'];
                if ($t !== -1 && $t !== 0) {
                    $list[] = $t;
                }
            }
            $p = $pData['prev'];
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
        for ($p = $this->tail->get(); $p !== -1;) {
            $pData = $this->queue->get((string) $p); 
            if ($pData['mode'] === 1) {
                $t = $pData['waiter'];
                if ($t !== -1 && $t !== 0) {
                    $list[] = $t;
                }
            }
            $p = $pData['prev'];
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
        return "[State = " . $this->getState() . ", "
            . ($this->hasQueuedThreads() ? "non" : "") . "empty queue]";
    }

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
     * waiters.  This method is designed for use in monitoring system
     * state, not for synchronization control.
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
    
    public function getQueue(): \Swoole\Table
    {
        return $this->queue;
    }
}
