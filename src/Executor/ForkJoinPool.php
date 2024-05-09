<?php

namespace Concurrent\Executor;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableFutureInterface,
    RunnableInterface,
    ThreadInterface,
    TimeUnit,
    WorkerFactoryInterface
};
use Concurrent\Lock\{
    LockSupport,
    ReentrantLockNotification
};
use Concurrent\Queue\{
    ArrayBlockingQueue,
    BlockingQueueInterface,
    ForkJoinWorkQueue
};
use Concurrent\Task\{
    AdaptedCallable,
    AdaptedRunnable,
    AdaptedRunnableAction,
    ForkJoinTask,
    RunnableExecuteAction
};
use Concurrent\Worker\{
    WorkerFactory,
    ForkJoinWorker
};

class ForkJoinPool implements ExecutorServiceInterface
{
    //use NotificationTrait;
    
    /**
     * Default idle timeout value (in milliseconds) for idle threads
     * to park waiting for new work before terminating.
     */
    public const DEFAULT_KEEPALIVE = 60000;

    /**
     * Initial capacity of work-stealing queue array.  Must be a power
     * of two, at least 2.
     */
    public const INITIAL_QUEUE_CAPACITY = 1 << 6;

    // conversions among short, int, long
    public const SMASK        = 0xffff;        // short bits == max index
    //public const LMASK        = 0xffffffff; // lower 32 bits of long
    //public const UMASK        = ~LMASK;      // upper 32 bits

    // masks and sentinels for queue indices
    public const MAX_CAP      = 0x7fff;        // max #workers - 1
    public const EXTERNAL_ID_MASK = 0x3ffe;   // max external queue id
    public const INVALID_ID       = 0x4000;   // unused external queue id

    public const EVENMASK     = 0xfffe;        // even short bits
    public const SQMASK       = 0x007e;        // max 64 (even) slots

    // Masks and units for WorkQueue.scanState and ctl sp subfield
    public const SCANNING     = 1;             // false when running tasks
    public const INACTIVE     = -2147483648; //1 << 31;       // must be negative
    public const SS_SEQ       = 1 << 16;       // version count

    // Mode bits for ForkJoinPool.config and WorkQueue.config
    public const MODE_MASK    = -65536;//0xffff << 16;  // top half of int
    public const LIFO_QUEUE   = 0;
    public const FIFO_QUEUE   = 1 << 16;
    public const SHARED_QUEUE = -2147483648; //1 << 31;       // must be negative

    //Inter process communication on sockets
    private static $notification;
    private static $port;
    
    /**
     * Creates a new ForkJoinWorkerThread. This factory is used unless
     * overridden in ForkJoinPool constructors.
     */
    public static $defaultForkJoinWorkerFactory;

    /**
     * Permission required for callers of methods that may start or
     * kill threads.
     */
    //private static $modifyThreadPermission;

    /**
     * Common (static) pool. Non-null for public use unless a static
     * construction exception, but internal usages null-check on use
     * to paranoically avoid potential initialization circularities
     * as well as to simplify generated code.
     */
    public static $common;

    /**
     * Common pool parallelism. To allow simpler use and management
     * when common pool threads are disabled, we allow the underlying
     * common.parallelism field to be zero, but in that case still report
     * parallelism as 1 to reflect resulting caller-runs mechanics.
     */
    public static $commonParallelism;

    /**
     * Limit on spare thread construction in tryCompensate.
     */
    public static $commonMaxSpares;

    /**
     * Sequence number for creating workerNamePrefix.
     */
    public static $poolNumberSequence;

    private $scopeArguments = [];

    /**
     * Returns the next sequence number. We don't expect this to
     * ever contend, so use simple builtin sync.
     */
    private static function nextPoolId(): int
    {   
        self::$mainLock->trylock();
        try {
            self::$poolNumberSequence->add(1);
            return self::$poolNumberSequence->get();
        } finally {
            self::$mainLock->unlock();
        }
    }

    // static configuration constants

    /**
     * Initial timeout value (in nanoseconds) for the thread
     * triggering quiescence to park waiting for new work. On timeout,
     * the thread will instead try to shrink the number of
     * workers. The value should be large enough to avoid overly
     * aggressive shrinkage during most transient stalls (long GCs
     * etc).
     */
    private const IDLE_TIMEOUT = 2000 * 1000 * 1000; // 2sec

    /**
     * Tolerance for idle timeouts, to cope with timer undershoots
     */
    private const TIMEOUT_SLOP = 20 * 1000 * 1000;  // 20ms

    /**
     * The initial value for commonMaxSpares during static
     * initialization. The value is far in excess of normal
     * requirements, but also far short of MAX_CAP and typical
     * OS thread limits, so allows JVMs to catch misuse/abuse
     * before running out of resources needed to do so.
     */
    private const DEFAULT_COMMON_MAX_SPARES = 256;

    /**
     * Number of times to spin-wait before blocking. The spins (in
     * awaitRunStateLock and awaitWork) currently use randomized
     * spins. If/when MWAIT-like intrinsics becomes available, they
     * may allow quieter spinning. The value of SPINS must be a power
     * of two, at least 4. The current value causes spinning for a
     * small fraction of typical context-switch times, well worthwhile
     * given the typical likelihoods that blocking is not necessary.
     */
    private const SPINS  = 1 << 11;

    /**
     * Increment for seed generators. See class ThreadLocal for
     * explanation.
     */
    private const SEED_INCREMENT = -1640531527; // 0x9e3779b9; in Java ints are 32 bit, so need to convert

    /*
     * Bits and masks for field ctl, packed with 4 16 bit subfields:
     * AC: Number of active running workers minus target parallelism
     * TC: Number of total workers minus target parallelism
     * SS: version count and status of top waiting thread
     * ID: poolIndex of top of Treiber stack of waiters
     *
     * When convenient, we can extract the lower 32 stack top bits
     * (including version bits) as sp=(int)ctl.  The offsets of counts
     * by the target parallelism and the positionings of fields makes
     * it possible to perform the most common checks via sign tests of
     * fields: When ac is negative, there are not enough active
     * workers, when tc is negative, there are not enough total
     * workers.  When sp is non-zero, there are waiting workers.  To
     * deal with possibly negative fields, we use casts in and out of
     * "short" and/or signed shifts to maintain signedness.
     *
     * Because it occupies uppermost bits, we can add one active count
     * using getAndAddLong of AC_UNIT, rather than CAS, when returning
     * from a blocked join.  Other updates entail multiple subfields
     * and masking, requiring CAS.
     */

    // Lower and upper word masks
    private const SP_MASK    = 0xffffffff;
    private const UC_MASK    = ~self::SP_MASK;

    // Active counts
    private const AC_SHIFT   = 48;
    private const AC_UNIT    = 0x0001 << self::AC_SHIFT;
    private const AC_MASK    = 0xffff << self::AC_SHIFT;

    // Total counts
    private const TC_SHIFT   = 32;
    private const TC_UNIT    = 0x0001 << self::TC_SHIFT;
    private const TC_MASK    = 0xffff << self::TC_SHIFT;
    private const ADD_WORKER = 0x0001 << (self::TC_SHIFT + 15); // sign

    // runState bits: SHUTDOWN must be negative, others arbitrary powers of two
    private const RSLOCK     = 1;
    private const RSIGNAL    = 1 << 1;
    private const STARTED    = 1 << 2;
    private const STOP       = 1 << 29;
    private const TERMINATED = 1 << 30;
    private const SHUTDOWN   = 1 << 31;

    // Instance fields
    public $ctl;// = 0;                   // main pool control
    public $runState;// = 0;               // lockable status
    public $config;// = 0;                    // parallelism, mode
    public $indexSeed;// = 0;                       // to generate worker index
    public $workQueues;     // main registry
    public $factory;
    public $ueh;  // per-worker UEH
    public $workerNamePrefix;       // to create worker name string
    public $stealCounter;    // also used as sync monitor
    private static $mainLock;

    /**
     * Acquires the runState lock; returns current (locked) runState.
     */
    private function lockRunState(?ThreadInterface $thread = null): int
    {
        $rs = 0;
        if ((($rs = $this->runState->get()) & self::RSLOCK) !== 0) {
            return $this->awaitRunStateLock($thread);
        } else {
            $rs |= self::RSLOCK;
            $this->runState->set($rs);
            return $rs;
        }
    }

    private function uRShift(int $a, int $b): int
    {
        if ($b == 0) {
            return $a;
        }
        return ($a >> $b) & ~(1<<(8*PHP_INT_SIZE-1)>>($b-1));
    }

    /**
     * Spins and/or blocks until runstate lock is available.  See
     * above for explanation.
     */
    private function awaitRunStateLock(?ThreadInterface $worker = null): int
    {
        $lock = new \Swoole\Atomic($this->stealCounter->get());
        $lock->add();
        $wasInterrupted = false;
        for ($spins = self::SPINS, $r = 0;;) {            
            if ((($rs = $this->runState->get()) & self::RSLOCK) == 0) {
                $ns = $rs | self::RSLOCK;
                $this->runState->set($ns);
                if ($wasInterrupted) {
                    try {
                        //Thread.currentThread().interrupt();
                        if ($worker !== null) {
                            $worker->interrupt();
                        }
                    } catch (\Throwable $ignore) {
                    }
                }
                return $ns;
            } elseif ($r === 0) {
                $r = ThreadLocalRandom::nextSecondarySeed(self::$threadMeta);
            } elseif ($spins > 0) {
                $r = gmp_init($r, 10);
                $r = gmp_xor($r, ThreadLocalRandom::signedMul($r, gmp_pow(2, 6)));
                $r = gmp_xor($r, ThreadLocalRandom::unsignedRightShift($r, 21));
                $r = intval(gmp_strval(gmp_xor($r, ThreadLocalRandom::signedMul($r, gmp_pow(2, 7)))));
                if ($r >= 0) {
                    --$spins;
                }
            } elseif (($rs & self::STARTED) == 0 || $lock->get() === 0) {
                //Thread.yield();   // initialization race
                usleep(1);
            } elseif ($rs = $this->runState->get()) {
                $this->runState->set($rs | self::RSIGNAL);
                self::$mainLock->trylock();
                try {
                    if (($this->runState->get() & self::RSIGNAL) !== 0) {
                        try {
                            $this->stealCounter->wait(-1);
                        } catch (\Throwable $ie) {
                            if (!($worker !== null && $worker instanceof ForkJoinWorker)) {
                                $wasInterrupted = true;
                            }
                        }
                    } else {
                        $this->stealCounter->wakeup();
                    }
                } finally {
                    self::$mainLock->unlock();
                }
            }
        }
    }

    /**
     * Unlocks and sets runState to newRunState.
     *
     * @param oldRunState a value returned from lockRunState
     * @param newRunState the next value (must have lock bit clear).
     */
    private function unlockRunState(int $oldRunState, int $newRunState): void
    {
        if ($this->runState->get() !== $oldRunState) {
            $this->runState->cmpset($oldRunState, $newRunState);              // clears RSIGNAL bit
            self::$mainLock->trylock();
            try {
                $this->stealCounter->wakeup();
            } finally {
                self::$mainLock->unlock();
            }
        } else {
            $this->runState->cmpset($oldRunState, $newRunState);
        }
    }

    private function createWorker(): bool
    {
        $fac = $this->factory;
        $ex = null;
        $wt = null;
        try {            
            if ($fac !== null && ($wt = $fac->newWorker(null, $this)) !== null) {
                $wt->start();
                return true;
            }
        } catch (\Throwable $rex) {
            $ex = $rex;
        }
        $this->deregisterWorker($wt, $ex);
        return false;
    }

    /**
     * Tries to add one worker, incrementing ctl counts before doing
     * so, relying on createWorker to back out on failure.
     *
     * @param c incoming ctl value, with total count negative and no
     * idle workers.  On CAS failure, c is refreshed and retried if
     * this holds (otherwise, a new worker is not needed).
     */
    private function tryAddWorker(int $c, ?int $debugStamp = null): void
    {
        $add = false;
        do {
            $nc = ((self::AC_MASK & ($c + self::AC_UNIT)) | (self::TC_MASK & ($c + self::TC_UNIT)));
            if ($this->ctl->get() == $c) {
                $rs = 0;
                $stop = 0;                 // check if terminating
                if (($stop = ($rs = $this->lockRunState()) & self::STOP) === 0) {
                    if ($this->ctl->get() == $c) {
                        $this->ctl->set($nc);
                        $add = true;
                    }
                }
                $this->unlockRunState($rs, $rs & ~self::RSLOCK);
                if ($stop != 0) {
                    break;
                }
                if ($add) {
                    $this->createWorker();
                    break;
                }
            }
        } while ((($c = $this->ctl->get()) & self::ADD_WORKER) !== 0 && ThreadLocalRandom::longToInt($c) === 0);
    }

    /**
     * Callback from ForkJoinWorkerThread constructor to establish and
     * record its WorkQueue.
     *
     * @param wt the worker thread
     * @return the worker's queue
     */
    public function registerWorker(ForkJoinWorker $wt): ForkJoinWorkQueue
    {
        $handler = null;
        //$wt->setDaemon(true);                           // configure thread
        if (($handler = $this->ueh) !== null) {
            //$wt->setUncaughtExceptionHandler($handler);
        }
        $w = new ForkJoinWorkQueue($this, $wt);
        $i = 0;                                    // assign a pool index
        $mode = $this->config->get() & self::MODE_MASK;
        $rs = $this->lockRunState();
        try {
            $ws = [];
            $n = 0;                    // skip if no array
            if (($ws = $this->workQueues) !== null && ($n = count($ws)) > 0) {
                $this->indexSeed->add(self::SEED_INCREMENT);  // unlikely to collide
                $s = $this->indexSeed->get();
                $m = $n - 1;
                $i = (($s << 1) | 1) & $m;               // odd-numbered indices
                if (isset($ws[$i])) {                  // collision
                    $probes = 0;                   // step by approx half n
                    $step = ($n <= 4) ? 2 : (($this->uRShift($n, 1)) & self::EVENMASK) + 2;
                    while (isset($ws[$i = ($i + $step) & $m])) {
                        if (++$probes >= $n) {
                            $ws = array_pad($ws, $n <<= 1, null);
                            $this->workQueues = $ws; 
                            $m = $n - 1;
                            $probes = 0;
                        }
                    }
                }
                $w->hint = $s;                           // use as random seed
                $w->config = $i | $mode;
                $w->scanState->set($i);                      // publication fence
                $this->workQueues[$i] = $w;
            }
        } finally {
            $this->unlockRunState($rs, $rs & ~self::RSLOCK);
        }
        $wt->setName($this->workerNamePrefix . $this->uRShift($i, 1));
        return $w;
    }

    /**
     * Final callback from terminating worker, as well as upon failure
     * to construct or start a worker.  Removes record of worker from
     * array, and adjusts counts. If pool is shutting down, tries to
     * complete termination.
     *
     * @param wt the worker thread, or null if construction failed
     * @param ex the exception causing failure, or null if none
     */
    public function deregisterWorker(?ForkJoinWorker $wt, ?\Throwable $ex = null): void
    {
        $w = null;
        if ($wt !== null && ($w = $wt->workQueue) != null) {
            $ws = [];                           // remove index from array
            $idx = $w->config & self::SMASK;
            $rs = $this->lockRunState();
            if (($ws = $this->workQueues) !== null && count($ws) > $idx && $ws[$idx] == $w) {
                $ws[$idx] = null;
            }
            $this->unlockRunState($rs, $rs & ~self::RSLOCK);
        }
        // decrement counts
        $success = false;
        while (!$success) {
            $c = $this->ctl->get();
            $nextC = ((self::AC_MASK & ($c - self::AC_UNIT)) |
                        (self::TC_MASK & ($c - self::TC_UNIT)) |
                        (self::SP_MASK & $c));
            // Attempt the CAS operation
            if ($this->ctl->get() == $c) {
                $this->ctl->set($nextC);
                $success = true;
            }
        }
        if ($w !== null) {
            $w->qlock->set(-1);                             // ensure set
            $w->transferStealCount($this);
            $w->cancelAll();                            // cancel remaining tasks
        }
        for (;;) {                                    // possibly replace
            $ws = [];
            $m = 0;
            $sp = 0;
            if ($this->tryTerminate(false, false) || $w == null || $w->isEmpty() ||
                ($this->runState->get() & self::STOP) != 0 || ($ws = $this->workQueues) === null ||
                ($m = count($ws) - 1) < 0) {              // already terminating
                break;
            }
            if (($sp = ThreadLocalRandom::longToInt($c = $this->ctl->get())) !== 0) {         // wake up replacement
                if ($this->tryRelease($c, $ws[$sp & $m], self::AC_UNIT)) {
                    break;
                }
            } elseif ($ex !== null && ($c & self::ADD_WORKER) !== 0) {
                $this->tryAddWorker($c);                      // create replacement
                break;
            } else {                                      // don't need replacement
                break;
            }
        }
        if ($ex === null) {                             // help clean on way out
            //ForkJoinTask::helpExpungeStaleExceptions();
            //@TODO ^^^^
        } else {  
            //@TODO ^^^^                                       // rethrow
            //ForkJoinTask::rethrow($ex);
        }
    }

    // Signalling

    /**
     * Tries to create or activate a worker if too few are active.
     *
     * @param ws the worker array to use to find signallees
     * @param q a WorkQueue --if non-null, don't retry if now empty
     */
    public function signalWork(?array $ws = null, ?ForkJoinWorkQueue $q = null) {
        $c = 0;
        $sp = 0;
        $i = 0;
        $v = null;
        $p = null;
        while (($c = $this->ctl->get()) < 0) {                       // too few active
            if (($sp = ThreadLocalRandom::longToInt($c)) === 0) {                  // no idle workers
                $debugStamp = hrtime(true);
                if (($c & self::ADD_WORKER) !== 0) {         // too few workers
                    $this->tryAddWorker($c, $debugStamp);
                }
                break;
            }
            if ($ws === null) {    
                // unstarted/terminated
                break;
            }
            if (count($ws) <= ($i = $sp & self::SMASK)) {       // terminated
                break;
            }
            if (($v = (isset($ws[$i]) ? $ws[$i] : null)) == null) {               // terminating
                break;
            }
            $vs = ($sp + self::SS_SEQ) & ~self::INACTIVE;        // next scanState
            $d = $sp - $v->scanState->get();                  // screen CAS
            $nc = (self::UC_MASK & ($c + self::AC_UNIT)) | (self::SP_MASK & $v->stackPred);
            if ($d == 0 && $this->ctl->get() === $c) {
                $this->ctl->set($nc);
                $v->scanState->set($vs);                      // activate v
                if (($p = $v->parker->get()) !== 0) {
                    LockSupport::unpark($p, hrtime(true));
                }
                break;
            }
            if ($q !== null && $q->isEmpty()) {         // no more work
                break;
            }
        }
    }

    /**
     * Signals and releases worker v if it is top of idle worker
     * stack.  This performs a one-shot version of signalWork only if
     * there is (apparently) at least one idle worker.
     *
     * @param c incoming ctl value
     * @param v if non-null, a worker
     * @param inc the increment to active count (zero when compensating)
     * @return true if successful
     */
    private function tryRelease(int $c, ?ForkJoinWorkQueue $v = null, ?int $inc = 0): bool
    {
        $sp = ThreadLocalRandom::longToInt($c);
        $vs = ($sp + self::SS_SEQ) & ~self::INACTIVE;
        $p = null;
        if ($v !== null && $v->scanState->get() == $sp) {          // v is at top of stack
            $nc = (self::UC_MASK & ($c + $inc)) | (self::SP_MASK & $v->stackPred);
            if ($this->ctl->get() == $c) {
                $this->ctl->set($nc);
                $v->scanState->set($vs);
                if (($p = $v->parker->get()) !== 0) {
                    LockSupport::unpark($p, hrtime(true));
                }
                return true;
            }
        }
        return false;
    }

    // Scanning for tasks

    /**
     * Top-level runloop for workers, called by ForkJoinWorkerThread.run.
     */
    public function runWorker(ForkJoinWorkQueue $w, ThreadInterface $process, ...$args) {
        //w.growArray();                   // allocate queue
        $seed = $w->hint;               // initially holds randomization hint
        $r = ($seed == 0) ? 1 : $seed;  // avoid 0 for xorShift
        $d = hrtime(true);
        for (;;) {
            if (($t = $this->scan($w, $r)) !== null) {
                $w->runTask($t, $w->owner, ...$args);
            } elseif (!$this->awaitWork($w, $r)) { //@TODO remove d and i
                break;
            }
            $r ^= ThreadLocalRandom::longToInt($r << 13);
            $r ^= $this->uRShift($r, 17);
            $r ^= ThreadLocalRandom::longToInt($r << 5);
        }
    }

    /**
     * Scans for and tries to steal a top-level task. Scans start at a
     * random location, randomly moving on apparent contention,
     * otherwise continuing linearly until reaching two consecutive
     * empty passes over all queues with the same checksum (summing
     * each base index of each queue, that moves on each steal), at
     * which point the worker tries to inactivate and then re-scans,
     * attempting to re-activate (itself or some other worker) if
     * finding a task; otherwise returning null to await work.  Scans
     * otherwise touch as little memory as possible, to reduce
     * disruption on other scanning threads.
     *
     * @param w the worker (via its WorkQueue)
     * @param r a random seed
     * @return a task, or null if none found
     */
    private function scan(?ForkJoinWorkQueue $w = null, ?int $r = 0): ?ForkJoinTask
    {
        $ws = [];
        $m = 0;
        $debugTimestamp = hrtime(true);
        if (($ws = $this->workQueues) !== null && ($m = count($ws) - 1) > 0 && $w !== null) {
            $ss = $w->scanState->get();                     // initially non-negative
            for ($origin = $r & $m, $k = $origin, $oldSum = 0, $checkSum = 0;;) {
                $q = null;
                $a = [];
                $t = null;
                $b = 0;
                $n = 0;
                $c = 0;
                if (($q = (isset($ws[$k]) ? $ws[$k] : null)) !== null) {
                    if (($n = ($b = $q->base->get()) - $q->top->get()) < 0 &&
                        ($a = $q->array)->count() > 0) {      // non-empty
                        //$i = (((count($a) - 1) & $b) << self::ASHIFT) + self::ABASE;
                        $i = ($q->top->get() - 1 + $q->capacity) % $q->capacity;
                        if (($t = $a->get((string) $i, 'task')) !== false && $q->base->get() === $b) {
                            if ($ss >= 0) {
                                if ($a->get((string) $i, 'task') === $t) {
                                    //$a->del((string) $i);
                                    //$q->base->set($b + 1);
                                    $t = $q->pop();
                                    if ($n < -1) {      // signal others
                                        $this->signalWork($ws, $q);
                                    }
                                    return $t;
                                }
                            } elseif ($oldSum == 0 &&   // try to activate
                                     $w->scanState->get() < 0) {
                                $this->tryRelease($c = $this->ctl->get(), isset($ws[$m & ThreadLocalRandom::longToInt($c)]) ? $ws[$m & ThreadLocalRandom::longToInt($c)] : null, self::AC_UNIT);
                            }
                        }
                        if ($ss < 0) {                  // refresh
                            $ss = $w->scanState->get();
                        }
                        $r ^= ThreadLocalRandom::longToInt($r << 1);
                        $r ^= $this->uRShift($r, 3);
                        $r ^= ThreadLocalRandom::longToInt($r << 10);

                        $k = $r & $m;
                        $origin = $k;           // move and rescan
                        $checkSum = 0;
                        $oldSum = $checkSum;
                        continue;
                    }
                    $checkSum += $b;
                }
                if (($k = ($k + 1) & $m) == $origin) {    // continue until stable
                    if (($ss >= 0 || ($ss == ($ss = $w->scanState->get()))) &&
                        $oldSum == ($oldSum = $checkSum)) {
                        if ($ss < 0 || $w->qlock->get() < 0) {    // already inactive
                            break;
                        }
                        $ns = $ss | self::INACTIVE;       // try to inactivate
                        $nc = ((self::SP_MASK & $ns) |
                                   (self::UC_MASK & (($c = $this->ctl->get()) - self::AC_UNIT)));
                        $w->stackPred = ThreadLocalRandom::longToInt($c);         // hold prev stack top
                        $w->scanState->set($ns);
                        if ($this->ctl->get() == $c) {
                            $this->ctl->set($nc);             
                            $ss = $ns;
                        } else {
                            $w->scanState->set($ss);
                        }         // back out
                    }
                    $checkSum = 0;
                }
            }
        }
        return null;
    }

    /**
     * Possibly blocks worker w waiting for a task to steal, or
     * returns false if the worker should terminate.  If inactivating
     * w has caused the pool to become quiescent, checks for pool
     * termination, and, so long as this is not the only worker, waits
     * for up to a given duration.  On timeout, if ctl has not
     * changed, terminates the worker, which will in turn wake up
     * another worker to possibly repeat this process.
     *
     * @param w the calling worker
     * @param r a random seed (for spins)
     * @return false if the worker should terminate
     */
    private function awaitWork(?ForkJoinWorkQueue $w = null, ?int $r = 0): bool
    {
        if ($w == null || $w->qlock->get() < 0) {                 // w is terminating
            return false;
        }
        for ($pred = $w->stackPred, $spins = self::SPINS, $ss = null;;) {
            if (($ss = $w->scanState->get()) >= 0) {
                break;
            } elseif ($spins > 0) {
                $rprev = $r;
                $r ^= ThreadLocalRandom::longToInt($r << 6);
                $r ^= $this->uRShift($r, 21);
                $r ^= ThreadLocalRandom::longToInt($r << 7);
                if ($r >= 0 && --$spins == 0) {         // randomize spins
                    $v = null;
                    $ws = []; 
                    $s = 0;
                    $j = 0; 
                    //$sc = null;
                    if ($pred != 0 && ($ws = $this->workQueues) !== null &&
                        ($j = $pred & self::SMASK) < count($ws) &&
                        ($v = isset($ws[$j]) ? $ws[$j] : null) !== null &&        // see if pred parking
                        ($v->parker->get() === 0 || $v->scanState->get() >= 0)) {
                        $spins = self::SPINS;
                    }             // continue spinning
                }
            } elseif ($w->qlock->get() < 0) {                  // recheck after spins
                return false;
            } elseif (!$w->owner->thread->isInterrupted()) {
                $c = 0;
                $prevctl = 0;
                $parkTime = 0;
                $deadline = 0;
                $ac = ThreadLocalRandom::longToInt(($c = $this->ctl->get()) >> self::AC_SHIFT) + ($this->config->get() & self::SMASK);
                if (($ac <= 0 && $this->tryTerminate(false, false)) ||
                    ($this->runState->get() & self::STOP) != 0) {          // pool terminating
                    return false;
                }
                if ($ac <= 0 && $ss == ThreadLocalRandom::longToInt($c)) {        // is last waiter
                    $prevctl = (self::UC_MASK & ($c + self::AC_UNIT)) | (self::SP_MASK & $pred);
                    $t = ThreadLocalRandom::unsignedRightShiftWithCast($c, self::TC_SHIFT, 16);  // shrink excess spares
                    if ($t > 2 && $this->ctl->get() === $c) {
                        $this->ctl->set($prevctl);
                        return false;                 // else use timed wait
                    }
                    $parkTime = self::IDLE_TIMEOUT * (($t >= 0) ? 1 : 1 - $t);
                    $deadline = hrtime(true) + $parkTime - self::TIMEOUT_SLOP;
                } else {
                    $deadline = 0;
                    $parkTime = 0;
                    $prevctl = 0;
                }
                $w->parker->set($w->owner->getPid());
                if ($w->scanState->get() < 0 && $this->ctl->get() == $c) {      // recheck before park
                    LockSupport::parkNanos($w->owner->thread, $parkTime);
                }
                $w->parker->set(0);
                if ($w->scanState->get() >= 0) {
                    break;
                }
                if ($parkTime != 0 && $this->ctl->get() == $c &&
                    $deadline - hrtime(true) <= 0 &&
                    $this->ctl->get() == $c) {
                    $this->ctl->set($prevctl);
                    return false;                     // shrink pool
                }
            }
        }
        return true;
    }

    /**
     * Tries to locate and execute tasks for a stealer of the given
     * task, or in turn one of its stealers, Traces currentSteal ->
     * currentJoin links looking for a thread working on a descendant
     * of the given task and with a non-empty queue to steal back and
     * execute tasks from. The first call to this method upon a
     * waiting join will often entail scanning/search, (which is OK
     * because the joiner has nothing better to do), but this method
     * leaves hints in workers to speed up subsequent calls.
     *
     * @param w caller
     * @param task the task to join
     */
    private function helpStealer(?ForkJoinWorkQueue $w, ?ForkJoinTask $task): void
    {
        $ws = $this->workQueues;
        $oldSum = 0;
        $checkSum = 0;
        $m = 0;
        if ($ws !== null && ($m = count($ws) - 1) >= 0 && $w !== null &&
            $task !== null) {
            do {                                       // restart point
                $checkSum = 0;                          // for stability check
                $subtask = null;
                $j = $w;
                $v = null;                    // v is subtask stealer
                for ($subtask = $task; ForkJoinTask::$status->get($subtask->getXid(), 'status')  >= 0; ) {
                    for ($h = $j->hint | 1, $k = 0, $i; ; $k += 2) {
                        if ($k > $m) {                   // can't find stealer
                            break 2;
                        }
                        if (($v = isset($ws[$i = ($h + $k) & $m]) ? $ws[$i = ($h + $k) & $m] : null) !== null) {
                            if ($v->currentSteal->get('task', 'task') == serialize($subtask)) {
                                $j->hint = $i;
                                break;
                            }
                            $checkSum += $v->base->get();
                        }
                    }
                    for (;;) {                         // help v or descend
                        $a = []; 
                        $b = 0;
                        $checkSum += ($b = $v->base->get());
                        $next = $v->currentJoin->get('task', 'task');
                        if (ForkJoinTask::$status->get($subtask->getXid(), 'status') < 0 || $j->currentJoin->get('task', 'task') != ($szs = serialize($subtask)) ||
                            $v->currentSteal->get('task', 'task') !== $szs) {// stale
                            break 2;
                        }
                        if ($b - $v->top->get() >= 0 || ($a = $v->array)->count() === 0) {
                            if (($subtask = unserialize($next)) === false) {
                                break 2;
                            }
                            $j = $v;
                            break;
                        }
                        //$i = (((count($a) - 1) & $b) << self::ASHIFT) + self::ABASE;
                        //$t = $a->get((string) $i) !== false ? unserialize($a->get((string) $i, 'task')) : null;
                        $i = ($v->top->get() - 1 + $v->capacity) % $v->capacity;
                        $t = $a->get((string) $i) !== false ? unserialize($a->get((string) $i, 'task')) : null;
                        if ($v->base->get() == $b) {
                            if ($t == null)  {           // stale
                                break 2;
                            }
                            if ($t->equals(unserialize($a->get((string) $i, 'task')))) {
                                //$v->base->set($b + 1);
                                $t = $v->pop();
                                $ps = $w->currentSteal->get('task', 'task');
                                $top = $w->top->get();
                                do {
                                    $w->currentSteal->set('task', ['task' => serialize($t)]);
                                     $t->doExec($w->owner);        // clear local tasks too
                                } while (ForkJoinTask::$status->get($task->getXid(), 'status') >= 0 && $w->top->get() != $top && ($t = $w->pop()) !== null);
                                
                                if ($ps === false) {
                                    $w->currentSteal->del('task');
                                } else {
                                    $w->currentSteal->set('task', ['task' => $ps]);
                                }

                                if (!$w->isEmpty()) {
                                    return;            // can't further help
                                }
                            }
                        }
                    }
                }
            } while (ForkJoinTask::$status->get($task->getXid(), 'status') >= 0 && $oldSum !== ($oldSum = $checkSum));
        }
    }

    /**
     * Tries to decrement active count (sometimes implicitly) and
     * possibly release or create a compensating worker in preparation
     * for blocking. Returns false (retryable by caller), on
     * contention, detected staleness, instability, or termination.
     *
     * @param w caller
     */
    private function tryCompensate(?ForkJoinWorkQueue $w = null): bool
    {
        $canBlock = false;
        $ws = [];
        $c = 0; 
        $m = 0;
        $pc = 0;
        $sp = 0;
        if ($w === null || $w->qlock->get() < 0 ||           // caller terminating
            ($ws = $this->workQueues) === null || ($m = count($ws) - 1) <= 0 ||
            ($pc = $this->config->get() & self::SMASK) === 0) {          // parallelism disabled
            $canBlock = false;
        } elseif (($sp = ThreadLocalRandom::longToInt($c = $this->ctl->get())) !== 0) {     // release idle worker
            $canBlock = $this->tryRelease($c, isset($ws[$sp & $m]) ? $ws[$sp & $m] : null , 0);
        } else {
            $ac = ThreadLocalRandom::unsignedRightShiftWithCast($c, self::TC_SHIFT, 32) + $pc;
            $tc = ThreadLocalRandom::unsignedRightShiftWithCast($c, self::TC_SHIFT, 16) + $pc;
            $nbusy = 0;                        // validate saturation
            for ($i = 0; $i <= $m; $i += 1) {        // two passes of odd indices
                $v = null;
                if (($v = isset($ws[(($i << 1) | 1) & $m]) ? $ws[(($i << 1) | 1) & $m] : null) !== null) {
                    if (($v->scanState->get() & self::SCANNING) !== 0) {
                        break;
                    }
                    $nbusy += 1;
                }
            }
            if ($nbusy != ($tc << 1) || $this->ctl->get() != $c) {
                $canBlock = false;                 // unstable or stale
            } elseif ($tc >= $pc && $ac > 1 && $w->isEmpty()) {
                $nc = ((self::AC_MASK & ($c - self::AC_UNIT)) |
                           (~self::AC_MASK & $c));       // uncompensated
                if ($this->ctl->get() === $c) {
                    $this->ctl->set($nc);
                    $canBlock = true;
                }
            } elseif ($tc >= self::MAX_CAP ||
                     ($this == self::$common && $tc >= $pc + self::$commonMaxSpares)) {
                throw new \Exception("Thread limit exceeded replacing blocked worker");
            } else {                                // similar to tryAddWorker
                $add = false;
                $rs = 0;      // CAS within lock
                $nc = ((self::AC_MASK & $c) |
                           (self::TC_MASK & ($c + self::TC_UNIT)));
                if ((($rs = $this->lockRunState()) & self::STOP) == 0) {
                    if ($this->ctl->get() == $c) {
                        $add = true;
                        $this->ctl->set($nc);
                    }
                }
                $this->unlockRunState($rs, $rs & ~self::RSLOCK);
                $canBlock = $add && $this->createWorker(); // throws on exception
            }
        }
        return $canBlock;
    }

    /**
     * Helps and/or blocks until the given task is done or timeout.
     *
     * @param w caller
     * @param task the task
     * @param deadline for timed waits, if nonzero
     * @return task status on exit
     */
    public function awaitJoin(?ForkJoinWorkQueue $w = null, ?ForkJoinTask $task = null, ?int $deadline = 0): int
    {
        $s = 0;
        if ($task !== null && $w !== null) {
            $prevJoin = $w->currentJoin->get('task', 'task');
            $w->currentJoin->set('task', ['task' => serialize($task)]);
            for (;;) {
                if (($s = ForkJoinTask::$status->get($task->getXid(), 'status')) < 0) {
                    break;
                }
                if ($w->isEmpty() || $w->tryRemoveAndExec($task, $w->owner)) {
                    $this->helpStealer($w, $task);
                }
                if (($s = ForkJoinTask::$status->get($task->getXid(), 'status')) < 0) {
                    break;
                }
                $ms = 0;
                $ns = 0;
                if ($deadline == 0) {
                    $ms = 0;
                } elseif (($ns = $deadline - hrtime(true)) <= 0) {
                    break;
                } elseif (($ms = floor($ns / 1000000)) <= 0) {
                    $ms = 1;
                }
                if ($this->tryCompensate($w)) {
                    $task->internalWait($ms);
                    $this->ctl->add(self::AC_UNIT);
                }                
            }
            if ($prevJoin === false) {
                $w->currentJoin->del('task');
            } else {
                $w->currentJoin->set('task', ['task' => $prevJoin]);
            }
        }
        return $s;
    }

    /**
     * Returns a (probably) non-empty steal queue, if one is found
     * during a scan, else null.  This method must be retried by
     * caller if, by the time it tries to use the queue, it is empty.
     */
    private function findNonEmptyStealQueue(): ?ForkJoinWorkQueue
    {
        $ws = [];
        $m = 0;  // one-shot version of scan loop
        $r = ThreadLocalRandom::nextSecondarySeed(self::$threadMeta);
        if (($ws = $this->workQueues) !== null && ($m = count($ws) - 1) >= 0) {
            for ($origin = $r & $m, $k = $origin, $oldSum = 0, $checkSum = 0;;) {
                $q = null;
                $b = 0;
                if (($q = (isset($ws[$k]) ? $ws[$k] : null)) !== null) {
                    if (($b = $q->base->get()) - $q->top->get() < 0) {
                        return $q;
                    }
                    $checkSum += $b;
                }
                if (($k = ($k + 1) & $m) == $origin) {
                    if ($oldSum == ($oldSum = $checkSum)) {
                        break;
                    }
                    $checkSum = 0;
                }
            }
        }
        return null;
    }

    /**
     * Runs tasks until {@code isQuiescent()}. We piggyback on
     * active count ctl maintenance, but rather than blocking
     * when tasks cannot be found, we rescan until all others cannot
     * find tasks either.
     */
    public function helpQuiescePool(?ForkJoinWorkQueue $w = null): void
    {
        $ps = $w->currentSteal->get('task', 'task'); // save context
        for ($active = true;;) {
            $c = 0;
            $q = null; 
            $t = null; 
            $b = 0;
            $w->execLocalTasks($w->owner);     // run locals before each scan
            if (($q = $this->findNonEmptyStealQueue()) != null) {
                if (!$active) {      // re-establish active count
                    $active = true;
                    $this->ctl->add(self::AC_UNIT);
                }
                if (($b = $q->base->get()) - $q->top->get() < 0 && ($t = $q->pollAt($b)) !== null) {
                    $w->currentSteal->set('task', ['task' => serialize($t)]);
                    $t->doExec($w->owner);
                    if (++$w->nsteals < 0) {
                        $w->transferStealCount($this);
                    }
                }
            } elseif ($active) {      // decrement active count without queuing
                $nc = (self::AC_MASK & (($c = $this->ctl->get()) - self::AC_UNIT)) | (~self::AC_MASK & $c);
                if (ThreadLocalRandom::unsignedRightShiftWithCast($nc, self::AC_SHIFT, 32) + ($this->config->get() & self::SMASK) <= 0) {
                    break;          // bypass decrement-then-increment
                }
                if ($this->ctl->get() == $c) {
                    $active = false;
                    $this->ctl->set($nc);
                }
            } elseif (ThreadLocalRandom::unsignedRightShiftWithCast($c = $this->ctl->get(), self::AC_SHIFT, 32) + ($this->config->get() & self::SMASK) <= 0 && $this->ctl->get() == $c) {
                $nc = $c + self::AC_UNIT;
                $this->ctl->set($nc);
                break;
            }                
        }
        $w->currentSteal->set("task", $ps);
    }

    /**
     * Gets and removes a local or stolen task for the given worker.
     *
     * @return a task, if available
     */
    public function nextTaskFor(ForkJoinWorkQueue $w): ?ForkJoinTask
    {
        for (;;) {
            $q = null;
            $b = 0;
            if (($t = $w->nextLocalTask()) !== null) {
                return $t;
            }
            if (($q = $this->findNonEmptyStealQueue()) === null) {
                return null;
            }
            if (($b = $q->base->get()) - $q->top->get() < 0 && ($t = $q->pollAt($b)) !== null) {
                return $t;
            }
        }
    }

    /**
     * Returns a cheap heuristic guide for task partitioning when
     * programmers, frameworks, tools, or languages have little or no
     * idea about task granularity.  In essence, by offering this
     * method, we ask users only about tradeoffs in overhead vs
     * expected throughput and its variance, rather than how finely to
     * partition tasks.
     *
     * In a steady state strict (tree-structured) computation, each
     * thread makes available for stealing enough tasks for other
     * threads to remain active. Inductively, if all threads play by
     * the same rules, each thread should make available only a
     * constant number of tasks.
     *
     * The minimum useful constant is just 1. But using a value of 1
     * would require immediate replenishment upon each steal to
     * maintain enough tasks, which is infeasible.  Further,
     * partitionings/granularities of offered tasks should minimize
     * steal rates, which in general means that threads nearer the top
     * of computation tree should generate more than those nearer the
     * bottom. In perfect steady state, each thread is at
     * approximately the same level of computation tree. However,
     * producing extra tasks amortizes the uncertainty of progress and
     * diffusion assumptions.
     *
     * So, users will want to use values larger (but not much larger)
     * than 1 to both smooth over transient shortages and hedge
     * against uneven progress; as traded off against the cost of
     * extra task overhead. We leave the user to pick a threshold
     * value to compare with the results of this call to guide
     * decisions, but recommend values such as 3.
     *
     * When all threads are active, it is on average OK to estimate
     * surplus strictly locally. In steady-state, if one thread is
     * maintaining say 2 surplus tasks, then so are others. So we can
     * just use estimated queue length.  However, this strategy alone
     * leads to serious mis-estimates in some non-steady-state
     * conditions (ramp-up, ramp-down, other stalls). We can detect
     * many of these by further considering the number of "idle"
     * threads, that are known to have zero queued tasks, so
     * compensate by a factor of (#idle/#active) threads.
     */
    public static function getSurplusQueuedTaskCount(ThreadInterface $t): int
    {
        $wt = null; 
        $pool = null; 
        $q = null;
        if ($t instanceof ForkJoinWorker) {
            $p = ($pool = ($wt = $t)->pool)->config & self::SMASK;
            $n = ($q = $wt->workQueue)->top->get() - $q->base->get();
            $a = ThreadLocalRandom::unsignedRightShiftWithCast($pool->ctl->get(), self::AC_SHIFT, 32) + $p;
            return $n - (($a > ($p = $this->uRShift($p, 1))) ? 0 :
                            (($a > ($p = $this->uRShift($p, 1))) ? 1 :
                                (($a > ($p = $this->uRShift($p, 1))) ? 2 :
                                    (($a > ($p = $this->uRShift($p, 1))) ? 4 : 8)
                                )
                            )
                        );
        }
        return 0;
    }

    //  Termination

    /**
     * Possibly initiates and/or completes termination.
     *
     * @param now if true, unconditionally terminate, else only
     * if no work and no active workers
     * @param enable if true, enable shutdown when next possible
     * @return true if now terminating or terminated
     */
    private function tryTerminate(bool $now, bool $enable): bool
    {
        $rs = 0;
        if ($this == self::$common) {                      // cannot shut down
            return false;
        }
        if (($rs = $this->runState->get()) >= 0) {
            if (!$enable) {
                return false;
            }
            $rs = $this->lockRunState();                  // enter SHUTDOWN phase
            $this->unlockRunState($rs, ($rs & ~self::RSLOCK) | self::SHUTDOWN);
        }

        if (($rs & self::STOP) == 0) {
            if (!$now) {                           // check quiescence
                for ($oldSum = 0;;) {        // repeat until stable
                    $ws = []; 
                    $w = null; 
                    $m = 0;
                    $b = 0;
                    $c = 0;
                    $checkSum = $this->ctl->get();
                    if (ThreadLocalRandom::unsignedRightShiftWithCast($checkSum, self::AC_SHIFT, 32) + ($this->config->get() & self::SMASK) > 0) {
                        return false;             // still active workers
                    }
                    if (($ws = $this->workQueues) === null || ($m = count($ws) - 1) <= 0) {
                        break;                    // check queues
                    }
                    for ($i = 0; $i <= $m; $i += 1) {
                        if (($w = isset($ws[$i]) ? $ws[$i] : null) !== null) {
                            if (($b = $w->base->get()) !== $w->top->get() || $w->scanState->get() >= 0 ||
                                $w->currentSteal->get('task', 'task') !== false) {
                                $this->tryRelease($c = $this->ctl->get(), isset($ws[$m & ThreadLocalRandom::longToInt($c)]) ? $ws[$m & ThreadLocalRandom::longToInt($c)] : null, self::AC_UNIT);
                                return false;     // arrange for recheck
                            }
                            $checkSum += $b;
                            if (($i & 1) == 0) {
                                $w->qlock->set(-1);     // try to disable external
                            }
                        }
                    }
                    if ($oldSum == ($oldSum = $checkSum)) { // ???
                        break;
                    }
                }
            }
            if (($this->runState->get() & self::STOP) == 0) {
                $rs = $this->lockRunState();              // enter STOP phase
                $this->unlockRunState($rs, ($rs & ~self::RSLOCK) | self::STOP);
            }
        }

        $pass = 0;                             // 3 passes to help terminate
        for ($oldSum = 0;;) {                // or until done or stable
            $ws = [];
            $w = null;
            $wt = null;
            $m = 0;
            $checkSum = $this->ctl->get();
            if (ThreadLocalRandom::unsignedRightShiftWithCast($checkSum, self::TC_SHIFT, 16) + ($this->config->get() & self::SMASK) <= 0 ||
                ($ws = $this->workQueues) === null || ($m = count($ws) - 1) <= 0) {
                if (($this->runState->get() & self::TERMINATED) === 0) {
                    $rs = $this->lockRunState();          // done
                    $this->unlockRunState($rs, ($rs & ~self::RSLOCK) | self::TERMINATED);
                    self::$mainLock->tryLock();
                    try {
                        self::$notification->notifyAll();
                    } finally {
                        self::$mainLock->unlock(); 
                    }
                }
                break;
            }
            for ($i = 0; $i <= $m; $i += 1) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) != null) {
                    $checkSum += $w->base->get();
                    $w->qlock->set(-1);                 // try to disable
                    if ($pass > 0) {
                        $w->cancelAll();            // clear queue
                        if ($pass > 1 && ($wt = $w->owner) !== null) {
                            if (!$wt->isInterrupted()) {
                                try {// unblock join
                                    $wt->interrupt();
                                } catch (\Throwable $ignore) {
                                }
                            }
                            if ($w->scanState->get() < 0) {
                                // wake up
                                LockSupport::unpark($wt->getPid(), hrtime(true));
                            }
                        }
                    }
                }
            }
            if ($checkSum != $oldSum) {             // unstable
                $oldSum = $checkSum;
                $pass = 0;
            } elseif ($pass > 3 && $pass > $m) {      // can't further help
                break;
            } elseif (++$pass > 1) {                // try to dequeue
                $c = 0;
                $j = 0;
                $sp = 0;            // bound attempts
                while ($j++ <= $m && ($sp = ThreadLocalRandom::longToInt($c = $this->ctl->get())) != 0) {
                    $this->tryRelease($c, isset($ws[$sp & $m]) ? $ws[$sp & $m] : null, self::AC_UNIT);
                }
            }
        }
        return true;
    }

    // External operations

    /**
     * Full version of externalPush, handling uncommon cases, as well
     * as performing secondary initialization upon the first
     * submission of the first task to the pool.  It also detects
     * first submission by an external thread and creates a new shared
     * queue if the one at index if empty or contended.
     *
     * @param task the task. Caller must ensure non-null.
     */
    private function externalSubmit(ForkJoinTask $task): void
    {
        $r = 0;                                    // initialize caller's probe
        if (($r = ThreadLocalRandom::getProbe(self::$threadMeta)) === 0) {
            ThreadLocalRandom::localInit(self::$threadMeta);
            $r = ThreadLocalRandom::getProbe(self::$threadMeta);
        }
        for (;;) {
            $ws = []; 
            $q = null; 
            $rs = 0;
            $m = 0;
            $k = 0;
            $move = false;
            if (($rs = $this->runState->get()) < 0) {
                $this->tryTerminate(false, false);     // help terminate
                throw new \Exception("RejectedExecution");
            } elseif (($rs & self::STARTED) === 0 ||     // initialize
                     (($ws = $this->workQueues) === null || ($m = count($ws) - 1) < 0)) {
                $ns = 0;
                $rs = $this->lockRunState();
                try {
                    if (($rs & self::STARTED) === 0) {
                        $this->stealCounter->set(0); 
                        // create workQueues array with size a power of two
                        $p = $this->config->get() & self::SMASK; // ensure at least 2 slots
                        $n = ($p > 1) ? $p - 1 : 1;
                        $n |= $this->uRShift($n, 1);
                        $n |= $this->uRShift($n, 2);
                        $n |= $this->uRShift($n, 4);
                        $n |= $this->uRShift($n, 8);
                        $n |= $this->uRShift($n, 16);
                        $n = ($n + 1) << 1;
                        $this->workQueues = array_fill(0, $n, null);
                        $ns = self::STARTED;
                    }
                } finally {
                    $this->unlockRunState($rs, ($rs & ~self::RSLOCK) | $ns);
                }
            } elseif (($q = isset($ws[$k = ($r & $m & self::SQMASK)]) ? $ws[$k = ($r & $m & self::SQMASK)] : null) !== null) {
                if ($q->qlock->get() == 0) {
                    $q->qlock->cmpset(0, 1);
                    //$a = $q->array;
                    //$s = $q->top->get();
                    $submitted = false; // initial submission or resizing
                    try {// locked version of push
                        $q->push($task);
                        $submitted = true;
                    } finally {
                        $q->qlock->cmpset(1, 0);
                    }
                    if ($submitted) {
                        $this->signalWork($ws, $q);
                        return;
                    }
                }
                $move = true; // move on failure
            } elseif ((($rs = $this->runState->get()) & self::RSLOCK) === 0) { // create new queue
                $q = new ForkJoinWorkQueue($this, null);
                $q->hint = $r;
                $q->config = $k | self::SHARED_QUEUE;
                $q->scanState->set(self::INACTIVE);
                $rs = $this->lockRunState();           // publish index
                if ($rs > 0 &&  ($ws = $this->workQueues) !== null &&
                    $k < count($ws) && (!isset($ws[$k]) || $ws[$k] === null)) {
                    $this->workQueues[$k] = $q;                 // else terminated
                }
                $this->unlockRunState($rs, $rs & ~self::RSLOCK);
            } else {
                $move = true;                   // move if busy
            }
            if ($move) {
                $r = ThreadLocalRandom::advanceProbe(self::$threadMeta, $r);
            }
        }
    }

    /**
     * Tries to add the given task to a submission queue at
     * submitter's current queue. Only the (vastly) most common path
     * is directly handled in this method, while screening for need
     * for externalSubmit.
     *
     * @param task the task. Caller must ensure non-null.
     */
    public function externalPush(ForkJoinTask $task): void
    {
        $ws = [];
        $q = null;
        $m = 0;
        $r = ThreadLocalRandom::getProbe(self::$threadMeta);
        $rs = $this->runState->get();
        if (($ws = $this->workQueues) != null && ($m = count($ws) - 1) >= 0 &&
            ($q = isset($ws[$m & $r & self::SQMASK]) ? $ws[$m & $r & self::SQMASK] : null) != null && $r != 0 && $rs > 0 &&
            $q->qlock->get() === 0) {
            $q->qlock->set(1);
            $a = [];
            $am = 0;
            $n = 0;
            $s = 0;
            $q->push($task);
            $q->qlock->set(0);
            return;
        }
        $this->externalSubmit($task);
    }

    /**
     * Returns common pool queue for an external thread.
     */
    public static function commonSubmitterQueue(): ?ForkJoinWorkQueue
    {
        $p = self::$common;
        $r = ThreadLocalRandom::getProbe(self::$threadMeta);
        $ws = [];
        $m = 0;
        return ($p !== null && ($ws = $p->workQueues) !== null &&
                ($m = count($ws) - 1) >= 0) ?
            ( isset($ws[$m & $r & self::SQMASK]) ? $ws[$m & $r & self::SQMASK] : null ) : null;
    }

    /**
     * Performs tryUnpush for an external submitter: Finds queue,
     * locks if apparently non-empty, validates upon locking, and
     * adjusts top. Each check can fail but rarely does.
     */
    public function tryExternalUnpush(/*ThreadInterface $thread,*/ForkJoinTask $task): bool
    {
        $ws = [];
        $w = null;
        $a = [];
        $m = 0;
        $s = 0;
        $r = ThreadLocalRandom::getProbe(self::$threadMeta);
        if (($ws = $this->workQueues) !== null && ($m = count($ws) - 1) >= 0 &&
            ($w = isset($ws[$m & $r & self::SQMASK]) ? $ws[$m & $r & self::SQMASK] : null) !== null &&
            ($a = $w->array) !== null && $w->array->count() > 0) {
            $j = ($w->top->get() - 1 + $w->capacity) % $w->capacity;
            if ($w->qlock->get() == 0) {
                $w->qlock->cmpset(0, 1);
                if ($w->top->get() == $s && $w->array == $a && $task->equals(unserialize($a->get((string) $j, 'task')))) {
                    $w->pop();
                    $w->qlock->set(0);
                    return true;
                }
                $w->qlock->cmpset(1, 0);
            }
        }
        return false;
    }

    public static $threadMeta;

    public function __construct(
        ?int $port = 1081,
        ?int $parallelism = null,
        ?WorkerFactoryInterface $factory = null,
        $handler = null,
        ?int $mode = null,
        ?string $workerNamePrefix = null
    ) {
        self::init($port);
        $this->runState = new \Swoole\Atomic\Long(0);
        $this->indexSeed = new \Swoole\Atomic\Long(0);
        $this->stealCounter = new \Swoole\Atomic(-1);       
        if (self::$poolNumberSequence === null) {
            self::$poolNumberSequence = new \Swoole\Atomic\Long(0);
        }
        $parallelism ??= min(self::MAX_CAP, swoole_cpu_num());        
        $factory ??= self::$defaultForkJoinWorkerFactory;
        $this->factory = $factory;
        $this->workerNamePrefix ??= "ForkJoinPool-" . $this->nextPoolId() . "-worker-";        
        $this->ueh = $handler;
        $mode = ($mode !== null) ? self::FIFO_QUEUE : self::LIFO_QUEUE;
        $this->config = new \Swoole\Atomic\Long(($parallelism & self::SMASK) | $mode);
        $np = -$parallelism; // offset ctl counts
        $ctl = (($np << self::AC_SHIFT) & self::AC_MASK) | (($np << self::TC_SHIFT) & self::TC_MASK);
        $this->ctl = new \Swoole\Atomic\Long($ctl);
    }

    private static $initiated = false;

    private static function init(?int $port = 1081): void
    {
        if (self::$initiated === false) {
            self::$initiated = true;        
            
            self::$mainLock = new \Swoole\Lock(SWOOLE_MUTEX);

            self::$notification = new ReentrantLockNotification(true);
            self::$port = $port;

            if (self::$defaultForkJoinWorkerFactory === null) {
                self::$defaultForkJoinWorkerFactory = new DefaultForkJoinWorkerFactory();
            }
            if (self::$common === null) {
                self::$common = self::makeCommonPool();

                $par = self::$common->config->get() & self::SMASK; // report 1 even if threads disabled
                self::$commonParallelism = $par > 0 ? $par : 1;
            }
            
            $meta = new \Swoole\Table(128);
            $meta->column('pid', \Swoole\Table::TYPE_INT);
            $meta->column('probe', \Swoole\Table::TYPE_INT);
            $meta->column('seed', \Swoole\Table::TYPE_INT);
            $meta->column('secondary', \Swoole\Table::TYPE_INT);
            $meta->create();

            self::$threadMeta = $meta;
            
            ForkJoinTask::init(self::$notification);                      
        }
    }

    private static function makeCommonPool(): ForkJoinPool
    {
        $parallelism = -1;
        $factory = null;
        $handler = null;

        $pp = getenv('ForkJoinPool.common.parallelism', true);
        $fp = getenv('ForkJoinPool.common.threadFactory', true);
        $hp = getenv('ForkJoinPool.common.exceptionHandler', true);
        $np = getenv('ForkJoinPool.common.notificationPort', true);

        $parallelism = ($pp !== false) ? intval($pp) : $parallelism;
        $factory = ($fp !== false) ? new $fp() : null;
        $handler = ($hp !== false) ? new $hp() : null;
        $notificationPort = ($np !== false) ? $np : self::$port;

        if ($parallelism < 0 && // default 1 less than #cores
            ($parallelism = swoole_cpu_num() - 1) <= 0) {
            $parallelism = 1;
        }
        if ($parallelism > self::MAX_CAP) {
            $parallelism = self::MAX_CAP;
        }
        $pool = new ForkJoinPool($notificationPort, $parallelism, $factory, $handler, self::LIFO_QUEUE, "ForkJoinPool.commonPool-worker-");
        LockSupport::init($notificationPort);

        return $pool;
    }

    public static function commonPool(?int $port = 1081): ForkJoinPool
    {
        self::init($port);
        return self::$common;
    }

    /**
     * Performs the given task, returning its result upon completion.
     * If the computation encounters an unchecked Exception or Error,
     * it is rethrown as the outcome of this invocation.  Rethrown
     * exceptions behave in the same way as regular exceptions, but,
     * when possible, contain stack traces (as displayed for example
     * using {@code ex.printStackTrace()}) of both the current thread
     * as well as the thread actually encountering the exception;
     * minimally only the latter.
     *
     * @param task the task
     * @return the task's result
     */
    public function invoke(ForkJoinTask $task) {
        $this->externalPush($task);
        return $task->join(null);
    }

    /**
     * Arranges for (asynchronous) execution of the given task.
     *
     * @param task the task scheduled for execution
     */
    public function execute(ForkJoinTask | RunnableInterface $task): void
    {
        if ($task instanceof ForkJoinTask) {
            $this->externalPush($task);
        } elseif ($task instanceof RunnableInterface) {
            $job = new RunnableExecuteAction($task);
            $this->externalPush($job);
        }
    }

    public function submit(ForkJoinTask | RunnableInterface | callable $task, &$result = null): ForkJoinTask
    {
        if ($task instanceof ForkJoinTask) {
            $this->externalPush($task);
            return $task;
        } elseif ($task instanceof RunnableInterface) {
            if ($result !== null) {
                $job = new AdaptedRunnable($task, $result);
                $this->externalPush($job);
                return $job;
            } else {
                $job = new AdaptedRunnableAction($task);
                $this->externalPush($job);
                return $job;
            }
        } elseif (is_callable($task)) {
            $job = new AdaptedCallable($task);
            $this->externalPush($job);
            return $job;
        }
    }


    public function invokeAll(array $tasks): array
    {
        $futures = [];

        $done = false;
        try {
            foreach ($tasks as $task) {
                $f = new AdaptedCallable($t);
                $futures[] = $f;
                $this->externalPush($f);
            }
            for ($i = 0, $size = count($futures); $i < $size; $i += 1) {
                $futures[$i]->quietlyJoin();
            }
            $done = true;
            return $futures;
        } finally {
            if (!$done) {
                for ($i = 0, $size = count($futures); $i < $size; $i += 1) {
                    $futures[$i]->cancel(false);
                }
            }
        }
    }

    /**
     * Returns the factory used for constructing new workers.
     *
     * @return the factory used for constructing new workers
     */
    public function getFactory(): WorkerFactoryInterface
    {
        return $this->factory;
    }

    /**
     * Returns the handler for internal worker threads that terminate
     * due to unrecoverable errors encountered while executing tasks.
     *
     * @return the handler, or {@code null} if none
     */
    public function getUncaughtExceptionHandler()
    {
        return $this->ueh;
    }

    /**
     * Returns the targeted parallelism level of this pool.
     *
     * @return the targeted parallelism level of this pool
     */
    public function getParallelism(): int
    {
        $par = 0;
        return (($par = ($this->config->get() & self::SMASK)) > 0) ? $par : 1;
    }

    /**
     * Returns the targeted parallelism level of the common pool.
     *
     * @return the targeted parallelism level of the common pool
     * @since 1.8
     */
    public static function getCommonPoolParallelism(): int
    {
        return self::$commonParallelism;
    }

    /**
     * Returns the number of worker threads that have started but not
     * yet terminated.  The result returned by this method may differ
     * from {@link #getParallelism} when threads are created to
     * maintain parallelism when others are cooperatively blocked.
     *
     * @return the number of worker threads
     */
    public function getPoolSize(): int
    {
        return ($this->config->get() & self::SMASK) + ThreadLocalRandom::unsignedRightShiftWithCast($this->ctl->get(), self::TC_SHIFT, 16);
    }

    /**
     * Returns {@code true} if this pool uses local first-in-first-out
     * scheduling mode for forked tasks that are never joined.
     *
     * @return {@code true} if this pool uses async mode
     */
    public function getAsyncMode(): bool
    {
        return ($this->config->get() & self::FIFO_QUEUE) !== 0;
    }

    /**
     * Returns an estimate of the number of worker threads that are
     * not blocked waiting to join tasks or for other managed
     * synchronization. This method may overestimate the
     * number of running threads.
     *
     * @return the number of worker threads
     */
    public function getRunningThreadCount(): int
    {
        $rc = 0;
        $ws = [];
        $w = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 1; $i < count($ws); $i += 2) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) != null && $w->isApparentlyUnblocked()) {
                    ++$rc;
                }
            }
        }
        return $rc;
    }

    /**
     * Returns an estimate of the number of threads that are currently
     * stealing or executing tasks. This method may overestimate the
     * number of active threads.
     *
     * @return the number of active threads
     */
    public function getActiveThreadCount(): int
    {
        $r = ($this->config->get() & self::SMASK) + ThreadLocalRandom::unsignedRightShiftWithCast($this->ctl->get(), self::AC_SHIFT, 32);
        return ($r <= 0) ? 0 : $r; // suppress momentarily negative values
    }

    /**
     * Returns {@code true} if all worker threads are currently idle.
     * An idle worker is one that cannot obtain a task to execute
     * because none are available to steal from other threads, and
     * there are no pending submissions to the pool. This method is
     * conservative; it might not return {@code true} immediately upon
     * idleness of all threads, but will eventually become true if
     * threads remain inactive.
     *
     * @return {@code true} if all threads are currently idle
     */
    public function isQuiescent(): bool
    {
        return ($this->config->get() & self::SMASK) + ThreadLocalRandom::unsignedRightShiftWithCast($this->ctl->get(), self::AC_SHIFT, 32) <= 0;
    }

    /**
     * Returns an estimate of the total number of tasks stolen from
     * one thread's work queue by another. The reported value
     * underestimates the actual total number of steals when the pool
     * is not quiescent. This value may be useful for monitoring and
     * tuning fork/join programs: in general, steal counts should be
     * high enough to keep threads busy, but low enough to avoid
     * overhead and contention across threads.
     *
     * @return the number of steals
     */
    public function getStealCount(): int
    {
        $sc = $this->stealCounter->get();
        $count = ($sc == -1) ? 0 : $sc;
        $ws = [];
        $w = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 1; $i < count($ws); $i += 2) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) != null)
                    $count += $w->nsteals;
            }
        }
        return $count;
    }

    /**
     * Returns an estimate of the total number of tasks currently held
     * in queues by worker threads (but not including tasks submitted
     * to the pool that have not begun executing). This value is only
     * an approximation, obtained by iterating across all threads in
     * the pool. This method may be useful for tuning task
     * granularities.
     *
     * @return the number of queued tasks
     */
    public function getQueuedTaskCount(): int
    {
        $count = 0;
        $ws = []; 
        $w = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 1; $i < count($ws); $i += 2) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) !== null) {
                    $count += $w->queueSize();
                }
            }
        }
        return $count;
    }

    /**
     * Returns an estimate of the number of tasks submitted to this
     * pool that have not yet begun executing.  This method may take
     * time proportional to the number of submissions.
     *
     * @return the number of queued submissions
     */
    public function getQueuedSubmissionCount(): int
    {
        $count = 0;
        $ws = []; 
        $w = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 0; $i < count($ws); $i += 2) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) !== null) {
                    $count += $w->queueSize();
                }
            }
        }
        return $count;
    }

    /**
     * Returns {@code true} if there are any tasks submitted to this
     * pool that have not yet begun executing.
     *
     * @return {@code true} if there are any queued submissions
     */
    public function hasQueuedSubmissions(): bool
    {
        $ws = [];
        $w = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 0; $i < count($ws); $i += 2) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) !== null && !$w->isEmpty()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Removes and returns the next unexecuted submission if one is
     * available.  This method may be useful in extensions to this
     * class that re-assign work in systems with multiple pools.
     *
     * @return the next submission, or {@code null} if none
     */
    public function pollSubmission(): ?ForkJoinTask
    {
        $ws = [];
        $w = null;
        $t = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 0; $i < count($ws); $i += 2) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) !== null && ($t = $w->poll()) !== null) {
                    return $t;
                }
            }
        }
        return null;
    }

    /**
     * Removes all available unexecuted submitted and forked tasks
     * from scheduling queues and adds them to the given collection,
     * without altering their execution status. These may include
     * artificially generated or wrapped tasks. This method is
     * designed to be invoked only when the pool is known to be
     * quiescent. Invocations at other times may not remove all
     * tasks. A failure encountered while attempting to add elements
     * to collection {@code c} may result in elements being in
     * neither, either or both collections when the associated
     * exception is thrown.  The behavior of this operation is
     * undefined if the specified collection is modified while the
     * operation is in progress.
     *
     * @param c the collection to transfer elements into
     * @return the number of elements transferred
     */
    public function drainTasksTo(array &$c): int
    {
        $count = 0;
        $ws = []; 
        $w = null; 
        $t = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 0; $i < count($ws); ++$i) {
                if (($w = isset($ws[$i]) ? $ws[$i] : null) !== null) {
                    while (($t = $w->poll()) !== null) {
                        $c[] = $t;
                        ++$count;
                    }
                }
            }
        }
        return $count;
    }

    /**
     * Returns a string identifying this pool, as well as its state,
     * including indications of run state, parallelism level, and
     * worker and task counts.
     *
     * @return a string identifying this pool, as well as its state
     */
    public function __toString(): string
    {
        // Use a single pass through workQueues to collect counts
        $qt = 0;
        $qs = 0;
        $rc = 0;
        $sc = $this->stealCounter->get();
        $st = ($sc == -1) ? 0 : $sc;
        $c = $this->ctl->get();
        $ws = [];
        $w = null;
        if (($ws = $this->workQueues) !== null) {
            for ($i = 0; $i < count($ws); ++$i) {
                if (($w = (isset($ws[$i]) ? $ws[$i] : null)) !== null) {
                    $size = $w->queueSize();
                    if (($i & 1) == 0) {
                        $qs += $size;
                    } else {
                        $qt += $size;
                        $st += $w->nsteals;
                        if ($w->isApparentlyUnblocked()) {
                            ++$rc;
                        }
                    }
                }
            }
        }
        $pc = ($this->config->get() & self::SMASK);
        $tc = $pc + ThreadLocalRandom::unsignedRightShiftWithCast($c, self::TC_SHIFT, 16);
        $ac = $pc + ThreadLocalRandom::unsignedRightShiftWithCast($c, self::AC_SHIFT, 32);
        if ($ac < 0) {// ignore transient negative
            $ac = 0;
        }
        $rs = $this->runState->get();
        $level = ((($rs & self::TERMINATED) != 0) ? "Terminated" :
                    ((($rs & self::STOP) != 0) ? "Terminating" :
                        ((($rs & self::SHUTDOWN) != 0) ? "Shutting down" : "Running")
                    )
                );
        return "Pool[" . $level .
            ", parallelism = " . $pc .
            ", size = " . $tc .
            ", active = " . $ac .
            ", running = " . $rc .
            ", steals = " . $st .
            ", tasks = " . $qt .
            ", submissions = " . $qs .
            "]";
    }

    /**
     * Possibly initiates an orderly shutdown in which previously
     * submitted tasks are executed, but no new tasks will be
     * accepted. Invocation has no effect on execution state if this
     * is the {@link #commonPool()}, and no additional effect if
     * already shut down.  Tasks that are in the process of being
     * submitted concurrently during the course of this method may or
     * may not be rejected.
     *
     * @throws SecurityException if a security manager exists and
     *         the caller is not permitted to modify threads
     *         because it does not hold {@link
     *         java.lang.RuntimePermission}{@code ("modifyThread")}
     */
    public function shutdown(): void
    {
        //checkPermission();
        $this->tryTerminate(false, true);
    }

    /**
     * Possibly attempts to cancel and/or stop all tasks, and reject
     * all subsequently submitted tasks.  Invocation has no effect on
     * execution state if this is the {@link #commonPool()}, and no
     * additional effect if already shut down. Otherwise, tasks that
     * are in the process of being submitted or executed concurrently
     * during the course of this method may or may not be
     * rejected. This method cancels both existing and unexecuted
     * tasks, in order to permit termination in the presence of task
     * dependencies. So the method always returns an empty list
     * (unlike the case for some other Executors).
     *
     * @return an empty list
     * @throws SecurityException if a security manager exists and
     *         the caller is not permitted to modify threads
     *         because it does not hold {@link
     *         java.lang.RuntimePermission}{@code ("modifyThread")}
     */
    public function shutdownNow(): array
    {
        //checkPermission();
        $this->tryTerminate(true, true);
        return [];
    }

    /**
     * Returns {@code true} if all tasks have completed following shut down.
     *
     * @return {@code true} if all tasks have completed following shut down
     */
    public function isTerminated(): bool
    {
        return ($this->runState->get() & self::TERMINATED) !== 0;
    }

    /**
     * Returns {@code true} if the process of termination has
     * commenced but not yet completed.  This method may be useful for
     * debugging. A return of {@code true} reported a sufficient
     * period after shutdown may indicate that submitted tasks have
     * ignored or suppressed interruption, or are waiting for I/O,
     * causing this executor not to properly terminate. (See the
     * advisory notes for class {@link ForkJoinTask} stating that
     * tasks should not normally entail blocking operations.  But if
     * they do, they must abort them on interrupt.)
     *
     * @return {@code true} if terminating but not yet terminated
     */
    public function isTerminating(): bool
    {
        $rs = $this->runState->get();
        return ($rs & self::STOP) !== 0 && ($rs & self::TERMINATED) === 0;
    }

    /**
     * Returns {@code true} if this pool has been shut down.
     *
     * @return {@code true} if this pool has been shut down
     */
    public function isShutdown(): bool
    {
        return ($this->runState->get() & self::SHUTDOWN) !== 0;
    }

    /**
     * Blocks until all tasks have completed execution after a
     * shutdown request, or the timeout occurs, or the current thread
     * is interrupted, whichever happens first. Because the {@link
     * #commonPool()} never terminates until program shutdown, when
     * applied to the common pool, this method is equivalent to {@link
     * #awaitQuiescence(long, TimeUnit)} but always returns {@code false}.
     *
     * @param timeout the maximum time to wait
     * @param unit the time unit of the timeout argument
     * @return {@code true} if this executor terminated and
     *         {@code false} if the timeout elapsed before termination
     * @throws InterruptedException if interrupted while waiting
     */
    public function awaitTermination(ThreadInterface $thread, int $timeout, string $unit): bool
   {
        if ($thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        if ($this == self::$common) {
            $this->awaitQuiescence($timeout, $unit);
            return false;
        }
        $nanos = TimeUnit::toNanos($timeout, $unit);
        if ($this->isTerminated()) {
            return true;
        }
        if ($nanos <= 0) {
            return false;
        }
        $deadline = hrtime(true) + $nanos;
        self::$mainLock->tryLock();
        try {
            for (;;) {
                if ($this->isTerminated()) {
                    return true;
                }
                if ($nanos <= 0) {
                    return false;
                }
                time_nanosleep($nanos > 0 ? $nanos : 999999);
                $nanos = $deadline - hrtime(true);
            }
        } finally {
            self::$mainLock->unlock();
        }
    }

    /**
     * If called by a ForkJoinTask operating in this pool, equivalent
     * in effect to {@link ForkJoinTask#helpQuiesce}. Otherwise,
     * waits and/or attempts to assist performing tasks until this
     * pool {@link #isQuiescent} or the indicated timeout elapses.
     *
     * @param timeout the maximum time to wait
     * @param unit the time unit of the timeout argument
     * @return {@code true} if quiescent; {@code false} if the
     * timeout elapsed.
     */
    public function awaitQuiescence(ThreadInterface $worker, int $timeout, string $unit): bool
    {
        $nanos = TimeUnit::toNanos($timeout, $unit);
        $wt = null;
        if (($worker instanceof ForkJoinWorker) &&
            ($wt = $worker)->pool == $this) {
            $this->helpQuiescePool($wt->workQueue, $worker);
            return true;
        }
        $startTime = hrtime(true);
        $ws = [];
        $r = 0;
        $m = 0;
        $found = true;
        while (!$this->isQuiescent() && ($ws = $this->workQueues) !== null &&
               ($m = count($ws) - 1) >= 0) {
            if (!$found) {
                if ((hrtime(true) - $startTime) > $nanos) {
                    return false;
                }
                //Thread.yield(); // cannot block
                usleep(1);
            }
            $found = false;
            for ($j = ($m + 1) << 2; $j >= 0; --$j) {
                $t = null;
                $q = null;
                $b = 0;
                $k = 0;
                if (($k = $r++ & $m) <= $m && $k >= 0 && ($q = isset($ws[$k]) ? $ws[$k] : null) !== null &&
                    ($b = $q->base->get()) - $q->top->get() < 0) {
                    $found = true;
                    if (($t = $q->pollAt($b)) !== null) {
                        $t->doExec($worker);
                    }
                    break;
                }
            }
        }
        return true;
    }

    /**
     * Waits and/or attempts to assist performing tasks indefinitely
     * until the {@link #commonPool()} {@link #isQuiescent}.
     */
    public static function quiesceCommonPool(): void
    {
        self::$common->awaitQuiescence(PHP_INT_MAX, TimeUnit::NANOSECONDS);
    }

    protected function newTaskFor(RunnableInterface | callable $action, &$value = null): RunnableFutureInterface
    {
        if ($action instanceof RunnableInterface) {
            return new AdaptedRunnable($action, $value);
        } else {
            return new AdaptedCallable($action);
        }
    }
    
    //@TODO - move to trait
    public function setScopeArguments(...$args)
    {
        $this->scopeArguments = $args;
    }

    public function getScopeArguments()
    {
        return $this->scopeArguments;
    }

    public static function managedBlock(ManagedBlockerInterface $blocker): void
    {
        //@TODO. no specific for fork join pool
        do {
        } while (!$blocker->isReleasable() && !$blocker->block());
    }
}
