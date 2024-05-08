<?php

namespace Concurrent\Executor;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableInterface,
    ThreadInterface
};
use Concurrent\TimeUnit;
use Concurrent\Lock\ReentrantLock;
use Concurrent\Queue\{
    ArrayBlockingQueue,
    BlockingQueueInterface
};
use Concurrent\Worker\WorkerFactory;

class DefaultPoolExecutor implements ExecutorServiceInterface
{
    private const COUNT_BITS = (PHP_INT_SIZE * 4) - 3;
    private const COUNT_MASK = (1 << self::COUNT_BITS) - 1;

    // runState is stored in the high-order bits
    private const RUNNING    = -1 << self::COUNT_BITS;
    private const SHUTDOWN   =  0 << self::COUNT_BITS;
    private const STOP       =  1 << self::COUNT_BITS;
    private const TIDYING    =  2 << self::COUNT_BITS;
    private const TERMINATED =  3 << self::COUNT_BITS;

    private $scopeArguments = [];

    private $workQueue;
    private $workerType;
    private $mainLock;

    /**
     * Set containing all worker processes in pool
     */
    private $workers = [];

    private $termination;// = mainLock.newCondition();

    protected static $initialized = false;
    protected $ctl;
    //protected $queueSize;
    protected $xid;

    public static $workerTasks;


    // Packing and unpacking ctl
    private static function runStateOf(int $c): int
    {
        return $c & ~self::COUNT_MASK;
    }

    private static function workerCountOf(int $c): int
    {
        return $c & self::COUNT_MASK;
    }
    
    private static function ctlOf(int $rs, int $wc): int
    {
        return $rs | $wc;
    }
 
    /*
    * Bit field accessors that don't require unpacking ctl.
    * These depend on the bit layout and on workerCount being never negative.
    */

    private static function runStateLessThan(int $c, int $s): bool
    {
        return $c < $s;
    }

    private static function runStateAtLeast(int $c, int $s): bool
    {
        return $c >= $s;
    }

    private static function isRunning(int $c): bool
    {
        return $c < self::SHUTDOWN;
    }

    /**
     * Attempts to CAS-increment the workerCount field of ctl.
    */
    private function compareAndIncrementWorkerCount(int $expect): bool
    {
        return $this->ctl->cmpset($expect, $expect + 1);
    }

    /**
     * Attempts to CAS-decrement the workerCount field of ctl.
    */
    private function compareAndDecrementWorkerCount(int $expect): bool
    {
        return $this->ctl->cmpset($expect, $expect - 1);
    }

    /**
     * Decrements the workerCount field of ctl. This is called only on
    * abrupt termination of a thread (see processWorkerExit). Other
    * decrements are performed within getTask.
    */
    private function decrementWorkerCount(): void
    {
        $this->ctl->sub(1);
    }

    /**
     * Tracks largest attained pool size. Accessed only under
     * mainLock.
     */
    private $largestPoolSize = 0;

    /**
     * Counter for completed tasks. Updated only on termination of
     * worker threads. Accessed only under mainLock.
     */
    private $completedTaskCount = 0;

    /*
     * All user control parameters are declared as volatiles so that
     * ongoing actions are based on freshest values, but without need
     * for locking, since no internal invariants depend on them
     * changing synchronously with respect to other actions.
     */

    /**
     * Factory for new threads. All threads are created using this
     * factory (via method addWorker).  All callers must be prepared
     * for addWorker to fail, which may reflect a system or user's
     * policy limiting the number of threads.  Even though it is not
     * treated as an error, failure to create threads may result in
     * new tasks being rejected or existing ones remaining stuck in
     * the queue.
     *
     * We go further and preserve pool invariants even in the face of
     * errors such as OutOfMemoryError, that might be thrown while
     * trying to create threads.  Such errors are rather common due to
     * the need to allocate a native stack in Thread.start, and users
     * will want to perform clean pool shutdown to clean up.  There
     * will likely be enough memory available for the cleanup code to
     * complete without encountering yet another OutOfMemoryError.
     */
    //private volatile ThreadFactory threadFactory;

    /**
     * Handler called when saturated or shutdown in execute.
     */
    //private volatile RejectedExecutionHandler handler;

    /**
     * Timeout in nanoseconds for idle threads waiting for work.
     * Threads use this timeout when there are more than corePoolSize
     * present or if allowCoreThreadTimeOut. Otherwise they wait
     * forever for new work.
     */
    private $keepAliveTime = 0;

    /**
     * If false (default), core threads stay alive even when idle.
     * If true, core threads use keepAliveTime to time out waiting
     * for work.
     */
    private $allowCoreThreadTimeOut = false;

    /**
     * Core pool size is the minimum number of workers to keep alive
     * (and not allow to time out etc) unless allowCoreThreadTimeOut
     * is set, in which case the minimum is zero.
     *
     * Since the worker count is actually stored in COUNT_BITS bits,
     * the effective limit is {@code corePoolSize & COUNT_MASK}.
     */
    private $corePoolSize = 0;

    /**
     * Maximum pool size.
     *
     * Since the worker count is actually stored in COUNT_BITS bits,
     * the effective limit is {@code maximumPoolSize & COUNT_MASK}.
     */
    private $maximumPoolSize = 0;

    /*
     * Methods for setting control state
     */

    /**
     * Transitions runState to given target, or leaves it alone if
     * already at least the given target.
     *
     * @param targetState the desired state, either SHUTDOWN or STOP
     *        (but not TIDYING or TERMINATED -- use tryTerminate for that)
     */
    private function advanceRunState(int $targetState): void
    {
        for (;;) {
            $c = $this->ctl->get();
            if (
                self::runStateAtLeast($c, $targetState) ||
                $this->ctl->cmpset($c, self::ctlOf($targetState, self::workerCountOf($c)))
            ) {
                break;
            }
        }
    }

    /**
     * Transitions to TERMINATED state if either (SHUTDOWN and pool
     * and queue empty) or (STOP and pool empty).  If otherwise
     * eligible to terminate but workerCount is nonzero, interrupts an
     * idle worker to ensure that shutdown signals propagate. This
     * method must be called following any action that might make
     * termination possible -- reducing worker count or removing tasks
     * from the queue during shutdown. The method is non-private to
     * allow access from ScheduledThreadPoolExecutor.
     */
    public function tryTerminate(): void
    {
        for (;;) {
            $c = $this->ctl->get();
            if (self::isRunning($c) ||
                self::runStateAtLeast($c, self::TIDYING) ||
                (self::runStateLessThan($c, self::STOP) && ! $this->workQueue->isEmpty()))
                return;
            if (self::workerCountOf($c) !== 0) { // Eligible to terminate
                $this->interruptIdleWorkers(self::ONLY_ONE);
                return;
            }

            $this->mainLock->lock();
            try {
                if ($this->ctl->compareAndSet($c, self::ctlOf(self::TIDYING, 0))) {
                    try {
                        $this->terminated();
                    } finally {
                        $this->ctl->set(self::ctlOf(self::TERMINATED, 0));
                        //termination.signalAll();
                        //container.close();
                    }
                    return;
                }
            } finally {
                $this->mainLock->unlock();
            }
            // else retry on failed CAS
        }
    }

    /*
     * Methods for controlling interrupts to worker threads.
     */

    /**
     * If there is a security manager, makes sure caller has
     * permission to shut down threads in general (see shutdownPerm).
     * If this passes, additionally makes sure the caller is allowed
     * to interrupt each worker thread. This might not be true even if
     * first check passed, if the SecurityManager treats some threads
     * specially.
     */
    private function checkShutdownAccess(): void
    {
    }

    /**
     * Interrupts all processes, even if active.
     */
    private function interruptWorkers(): void
    {
        foreach ($this->workers as $w) {
            try {
                $w->interrupt();
            } catch (\Exception $ignore) {
                //
                fwrite(STDERR, "Exception in interruptWorkers\n");
            }
        }
    }

    /**
     * Interrupts threads that might be waiting for tasks (as
     * indicated by not being locked) so they can check for
     * termination or configuration changes. Ignores
     * SecurityExceptions (in which case some threads may remain
     * uninterrupted).
     *
     * @param onlyOne If true, interrupt at most one worker. This is
     * called only from tryTerminate when termination is otherwise
     * enabled but there are still other workers.  In this case, at
     * most one waiting worker is interrupted to propagate shutdown
     * signals in case all threads are currently waiting.
     * Interrupting any arbitrary thread ensures that newly arriving
     * workers since shutdown began will also eventually exit.
     * To guarantee eventual termination, it suffices to always
     * interrupt only one idle worker, but shutdown() interrupts all
     * idle workers so that redundant workers exit promptly, not
     * waiting for a straggler task to finish.
     */
    private function interruptIdleWorkers(bool $onlyOne = false): void
    {
        $this->mainLock->lock();
        try {
            foreach ($this->workers as $w) {
                $t = $w->thread;
                if (!$t->isInterrupted() && $w->trylock()) {
                    try {
                        $t->interrupt();
                    } finally {
                        $w->unlock();
                    }
                }
                if ($onlyOne) {
                    break;
                }
            }
        } finally {
            $this->mainLock->unlock();
        }
    }

    private const ONLY_ONE = true;

    /*
     * Misc utilities, most of which are also exported to
     * ScheduledThreadPoolExecutor
     */

    /**
     * Invokes the rejected execution handler for the given command.
     * Package-protected for use by ScheduledThreadPoolExecutor.
     */
    protected function reject(RunnableInterface $command): void
    {
    }

    /**
     * Performs any further cleanup following run state transition on
     * invocation of shutdown.  A no-op here, but used by
     * ScheduledThreadPoolExecutor to cancel delayed tasks.
     */
    public function onShutdown(): void
    {
    }

    /**
     * Drains the task queue into a new list, normally using
     * drainTo. But if the queue is a DelayQueue or any other kind of
     * queue for which poll or drainTo may fail to remove some
     * elements, it deletes them one by one.
     */
    private function drainQueue(): array
    {
        $q = $this->workQueue;
        $taskList = [];
        $q->drainTo($taskList);
        if (!$q->isEmpty()) {
            $runnable = [];
            foreach ($q->toArray($runnable) as $r) {
                if ($q->remove($r)) {
                    $taskList[] = $r;
                }
            }
        }
        return $taskList;
    }

    /**
     * Checks if a new worker can be added with respect to current
     * pool state and the given bound (either core or maximum). If so,
     * the worker count is adjusted accordingly, and, if possible, a
     * new worker is created and started, running firstTask as its
     * first task. This method returns false if the pool is stopped or
     * eligible to shut down. It also returns false if the thread
     * factory fails to create a thread when asked.  If the thread
     * creation fails, either due to the thread factory returning
     * null, or due to an exception (typically OutOfMemoryError in
     * Thread.start()), we roll back cleanly.
     *
     * @param firstTask the task the new thread should run first (or
     * null if none). Workers are created with an initial first task
     * (in method execute()) to bypass queuing when there are fewer
     * than corePoolSize threads (in which case we always start one),
     * or when the queue is full (in which case we must bypass queue).
     * Initially idle threads are usually created via
     * prestartCoreThread or to replace other dying workers.
     *
     * @param core if true use corePoolSize as bound, else
     * maximumPoolSize. (A boolean indicator is used here rather than a
     * value to ensure reads of fresh values after checking other pool
     * state).
     * @return true if successful
     */
    private function addWorker(?RunnableInterface $firstTask, bool $core)
    {
        for ($c = $this->ctl->get();;) {
            // Check if queue empty only if necessary.
            if (self::runStateAtLeast($c, self::SHUTDOWN)
                && (self::runStateAtLeast($c, self::STOP)
                    || $firstTask !== null
                    || $this->workQueue->isEmpty())) {
                return false;
            }

            for (;;) {
                if (self::workerCountOf($c)
                    >= (($core ? $this->corePoolSize : $this->maximumPoolSize) & self::COUNT_MASK)) {
                    return false;
                }
                if (self::compareAndIncrementWorkerCount($c)) {
                    break 2;
                }
                $c = $this->ctl->get();  // Re-read ctl
                if (self::runStateAtLeast($c, self::SHUTDOWN)) {
                    continue 2;
                }
                // else CAS failed due to workerCount change; retry inner loop
            }
        }

        $workerStarted = false;
        $workerAdded = false;        
        try {
            $w = WorkerFactory::create($this->workerType, $firstTask, $this);
            $t = $w->thread;            
            $this->mainLock->lock();
            try {
                // Recheck while holding lock.
                // Back out on ThreadFactory failure or if
                // shut down before lock acquired.
                $c = $this->ctl->get();

                if (self::isRunning($c) ||
                    (self::runStateLessThan($c, self::STOP) && $firstTask == null)) {
                    $this->workers[] = $w;
                    $workerAdded = true;
                    $s = count($this->workers);
                    if ($s > $this->largestPoolSize) {
                        $this->largestPoolSize = $s;
                    }
                }
            } finally {
                $this->mainLock->unlock();
            }
            if ($workerAdded) {
                $t->start();
                $workerStarted = true;
            }
        } finally {
            if (!$workerStarted) {
                $this->addWorkerFailed($w);
            }
        }
        return $workerStarted;
    }

    /**
     * Rolls back the worker thread creation.
     * - removes worker from workers, if present
     * - decrements worker count
     * - rechecks for termination, in case the existence of this
     *   worker was holding up termination
     */
    private function addWorkerFailed(?RunnableInterface $w): void
    {
        $this->mainLock->lock();
        try {
            if ($w !== null) {
                foreach ($this->workers as $key => $val) {
                    if ($val == $w) {
                        unset($this->workers[$key]);
                        break;
                    }
                }
            }
            $this->decrementWorkerCount();
            $this->tryTerminate();
        } finally {
            $this->mainLock->unlock();
        }
    }

    /**
     * Performs cleanup and bookkeeping for a dying worker. Called
     * only from worker threads. Unless completedAbruptly is set,
     * assumes that workerCount has already been adjusted to account
     * for exit.  This method removes thread from worker set, and
     * possibly terminates the pool or replaces the worker if either
     * it exited due to user task exception or if fewer than
     * corePoolSize workers are running or queue is non-empty but
     * there are no workers.
     *
     * @param w the worker
     * @param completedAbruptly if the worker died due to user exception
     */
    private function processWorkerExit(?RunnableInterface $w, bool $completedAbruptly): void
    {
        if ($completedAbruptly) {// If abrupt, then workerCount wasn't adjusted
            $this->decrementWorkerCount();
        }

        $this->mainLock->lock();
        try {
            foreach ($this->workers as $key => $val) {
                if ($val == $w) {
                    unset($this->workers[$key]);
                    break;
                }
            }
        } finally {
            $this->mainLock->unlock();
        }

        $this->tryTerminate();

        $c = $this->ctl->get();
        if (self::runStateLessThan($c, self::STOP)) {
            if (!$completedAbruptly) {
                $min = $this->allowCoreThreadTimeOut ? 0 : $this->corePoolSize;
                if ($min == 0 && ! $this->workQueue->isEmpty()) {
                    $min = 1;
                }
                if (self::workerCountOf($c) >= $min) {
                    return; // replacement not needed
                }
            }
            $this->addWorker(null, false);
        }
    }

    /**
     * Performs blocking or timed wait for a task, depending on
     * current configuration settings, or returns null if this worker
     * must exit because of any of:
     * 1. There are more than maximumPoolSize workers (due to
     *    a call to setMaximumPoolSize).
     * 2. The pool is stopped.
     * 3. The pool is shutdown and the queue is empty.
     * 4. This worker timed out waiting for a task, and timed-out
     *    workers are subject to termination (that is,
     *    {@code allowCoreThreadTimeOut || workerCount > corePoolSize})
     *    both before and after the timed wait, and if the queue is
     *    non-empty, this worker is not the last thread in the pool.
     *
     * @return task, or null if the worker must exit, in which case
     *         workerCount is decremented
     */
    private function getTask(?ThreadInterface $thread = null): ?RunnableInterface
    {
        $timedOut = false; // Did the last poll() time out?

        for (;;) {
            $c = $this->ctl->get();

            // Check if queue empty only if necessary.
            if (self::runStateAtLeast($c, self::SHUTDOWN)
                && (self::runStateAtLeast($c, self::STOP) || $this->workQueue->isEmpty())) {
                $this->decrementWorkerCount();
                return null;
            }

            $wc = self::workerCountOf($c);

            // Are workers subject to culling?
            $timed = $this->allowCoreThreadTimeOut || $wc > $this->corePoolSize;

            if (($wc > $this->maximumPoolSize || ($timed && $timedOut))
                && ($wc > 1 || $this->workQueue->isEmpty())) {
                if ($this->compareAndDecrementWorkerCount($c)) {
                    return null;
                }
                continue;
            }

            try {
                $r = $timed ?
                    $this->workQueue->poll($this->keepAliveTime, TimeUnit::NANOSECONDS, $thread) :
                    $this->workQueue->take($thread);
                    
                if (is_object($r)) {
                    return $r;
                }
                if ($r !== null) {
                    return unserialize($r);
                }
                $timedOut = true;
            } catch (\Exception $e) {
                $timedOut = false;
            }
        }
    }

    /**
     * Main worker run loop.  Repeatedly gets tasks from queue and
     * executes them, while coping with a number of issues:
     *
     * 1. We may start out with an initial task, in which case we
     * don't need to get the first one. Otherwise, as long as pool is
     * running, we get tasks from getTask. If it returns null then the
     * worker exits due to changed pool state or configuration
     * parameters.  Other exits result from exception throws in
     * external code, in which case completedAbruptly holds, which
     * usually leads processWorkerExit to replace this thread.
     *
     * 2. Before running any task, the lock is acquired to prevent
     * other pool interrupts while the task is executing, and then we
     * ensure that unless pool is stopping, this thread does not have
     * its interrupt set.
     *
     * 3. Each task run is preceded by a call to beforeExecute, which
     * might throw an exception, in which case we cause thread to die
     * (breaking loop with completedAbruptly true) without processing
     * the task.
     *
     * 4. Assuming beforeExecute completes normally, we run the task,
     * gathering any of its thrown exceptions to send to afterExecute.
     * We separately handle RuntimeException, Error (both of which the
     * specs guarantee that we trap) and arbitrary Throwables.
     * Because we cannot rethrow Throwables within Runnable.run, we
     * wrap them within Errors on the way out (to the thread's
     * UncaughtExceptionHandler).  Any thrown exception also
     * conservatively causes thread to die.
     *
     * 5. After task.run completes, we call afterExecute, which may
     * also throw an exception, which will also cause thread to
     * die. According to JLS Sec 14.20, this exception is the one that
     * will be in effect even if task.run throws.
     *
     * The net effect of the exception mechanics is that afterExecute
     * and the thread's UncaughtExceptionHandler have as accurate
     * information as we can provide about any problems encountered by
     * user code.
     *
     * @param w the worker
     */
    public function runWorker(ThreadInterface $w, ...$args): void
    {
        $task = $w->firstTask;
        $w->firstTask = null;
        $w->unlock(); // allow interrupts
        $completedAbruptly = true;
        try {
            while ($task !== null || ($task = $this->getTask($w->thread)) !== null) {
                $w->lock();
                // If pool is stopping, ensure thread is interrupted;
                // if not, ensure thread is not interrupted.  This
                // requires a recheck in second case to deal with
                // shutdownNow race while clearing interrupt
                /*if (self::runStateAtLeast($this->ctl->get(), self::STOP) || !$w->thread->isInterrupted()) {
                    $w->thread->interrupt();
                }*/                
                try {
                    $this->beforeExecute($w, $task);
                    try {
                        if (self::$taskCounter->add() % 1000 === 0) {
                            fwrite(STDERR, getmypid() . ": tasks executed: " . self::$taskCounter->get() . " at " . hrtime(true) . "\n");
                        }
                        $task->run($w, ...$args);
                        $this->afterExecute($task, null);
                    } catch (\Throwable $ex) {
                        $this->afterExecute($task, $ex);
                        throw $ex;
                    }
                } finally {
                    $task = null;
                    $w->unlock();
                }
            }
            $completedAbruptly = false;
        } finally {
            $this->processWorkerExit($w, $completedAbruptly);
        }
    }

    public static $taskCounter;

    public function __construct(
        ?int $corePoolSize = null,
        ?int $maximumPoolSize = null,
        int $keepAliveTime = 0,
        string $unit = TimeUnit::MILLISECONDS,
        BlockingQueueInterface $workQueue = null,
        string $workerType = 'process'
    ) {
        $this->xid = md5(microtime() . rand());

        //$this->mainLock = new \Swoole\Lock(SWOOLE_MUTEX);
        $this->mainLock = new ReentrantLock(true);
        $this->ctl = new \Swoole\Atomic\Long(self::ctlOf(self::RUNNING, 0));        
        $corePoolSize = $corePoolSize ?? swoole_cpu_num();
        $maximumPoolSize = $maximumPoolSize ?? $corePoolSize;
        if (
            $corePoolSize <= 0 || $keepAliveTime < 0 || $maximumPoolSize < 0 || $maximumPoolSize < $corePoolSize
        ) {
            fwrite(STDERR, "Illegal argument exception in constructor\n");
            throw new \Exception("Illegal argument");
        }
        $this->corePoolSize = $corePoolSize;
        $this->maximumPoolSize = $maximumPoolSize;

        $this->workQueue = $workQueue ?? new ArrayBlockingQueue();
        $this->keepAliveTime = TimeUnit::toNanos($keepAliveTime, $unit);
        $this->workerType = $workerType;
         

        self::$workerTasks = new \Swoole\Table(200);
        self::$workerTasks->column('pid', \Swoole\Table::TYPE_INT);
        self::$workerTasks->column('tasks', \Swoole\Table::TYPE_INT);
        self::$workerTasks->create();

        self::$taskCounter = new \Swoole\Atomic\Long(0);  
    }

    /**
     * Executes the given task sometime in the future.  The task
     * may execute in a new thread or in an existing pooled thread.
     *
     * If the task cannot be submitted for execution, either because this
     * executor has been shutdown or because its capacity has been reached,
     * the task is handled by the current {@link RejectedExecutionHandler}.
     *
     * @param command the task to execute
     * @throws RejectedExecutionException at discretion of
     *         {@code RejectedExecutionHandler}, if the task
     *         cannot be accepted for execution
     * @throws NullPointerException if {@code command} is null
     */
    public function execute(RunnableInterface | callable $command): void
    {
        /*
         * Proceed in 3 steps:
         *
         * 1. If fewer than corePoolSize threads are running, try to
         * start a new thread with the given command as its first
         * task.  The call to addWorker atomically checks runState and
         * workerCount, and so prevents false alarms that would add
         * threads when it shouldn't, by returning false.
         *
         * 2. If a task can be successfully queued, then we still need
         * to double-check whether we should have added a thread
         * (because existing ones died since last checking) or that
         * the pool shut down since entry into this method. So we
         * recheck state and if necessary roll back the enqueuing if
         * stopped, or start a new thread if there are none.
         *
         * 3. If we cannot queue task, then we try to add a new
         * thread.  If it fails, we know we are shut down or saturated
         * and so reject the task.
         */
        $c = $this->ctl->get();
        if (self::workerCountOf($c) < $this->corePoolSize) {
            if ($this->addWorker($command, true)) {
                return;
            }
            $c = $this->ctl->get();
        }
        if (self::isRunning($c) && $this->workQueue->offer($command)) {
            $recheck = $this->ctl->get();
            if (!self::isRunning($recheck) && $this->remove($command)) {
                $this->reject($command);
            } elseif (self::workerCountOf($recheck) === 0) {
                $this->addWorker(null, false);
            }
        } elseif (!$this->addWorker($command, false)) {
            $this->reject($command);
        }
    }

    /**
     * Initiates an orderly shutdown in which previously submitted
     * tasks are executed, but no new tasks will be accepted.
     * Invocation has no additional effect if already shut down.
     *
     * <p>This method does not wait for previously submitted tasks to
     * complete execution.  Use {@link #awaitTermination awaitTermination}
     * to do that.
     *
     **/
    public function shutdown(): void
    {
        $this->mainLock->lock();
        try {
            $this->checkShutdownAccess();
            $this->advanceRunState(self::SHUTDOWN);
            $this->interruptIdleWorkers();
            $this->onShutdown();
        } finally {
            $this->mainLock->unlock();
        }
        $this->tryTerminate();
    }

    public function shutdownNow(): array
    {
        $tasks = [];
        $this->mainLock->lock();
        try {
            $this->checkShutdownAccess();
            $this->advanceRunState(self::STOP);
            $this->interruptWorkers();
            $tasks = $this->drainQueue();
        } finally {
            $this->mainLock->unlock();
        }
        $this->tryTerminate();
        return $tasks;
    }

    public function isShutdown(): bool
    {
        return self::runStateAtLeast($this->ctl->get(), self::SHUTDOWN);
    }

    /** Used by ScheduledThreadPoolExecutor. */
    public function isStopped(): bool
    {
        return self::runStateAtLeast($this->ctl->get(), self::STOP);
    }

    /**
     * Returns true if this executor is in the process of terminating
     * after {@link #shutdown} or {@link #shutdownNow} but has not
     * completely terminated.  This method may be useful for
     * debugging. A return of {@code true} reported a sufficient
     * period after shutdown may indicate that submitted tasks have
     * ignored or suppressed interruption, causing this executor not
     * to properly terminate.
     *
     * @return {@code true} if terminating but not yet terminated
     */
    public function isTerminating(): bool
    {
        $c = $this->ctl->get();
        return self::runStateAtLeast($c, self::SHUTDOWN) && self::runStateLessThan($c, self::TERMINATED);
    }

    public function isTerminated(): bool
    {
        return self::runStateAtLeast($this->ctl->get(), self::TERMINATED);
    }

    public function awaitTermination(ThreadInterface $thread, int $timeout, string $unit)
    {
        $nanos = TimeUnit::toNanos($timeout, $unit);
        $this->mainLock->trylock();
        try {
            for (;;) {
                if (self::runStateAtLeast($this->ctl->get(), self::TERMINATED)) {
                    return true;
                }
                if ($nanos <= 0 || $timeout <= 0) {
                    return false;
                }
                if ($unit == TimeUnit::SECONDS) {
                    sleep(1);
                    $timeout -= 1;
                } else {
                    time_nanosleep(0, $nanos);
                    $nanos = -1;
                }
            }
        } finally {
            $this->mainLock->unlock();
        }
    }

    /**
     * Sets the core number of threads.  This overrides any value set
     * in the constructor.  If the new value is smaller than the
     * current value, excess existing threads will be terminated when
     * they next become idle.  If larger, new threads will, if needed,
     * be started to execute any queued tasks.
     *
     * @param corePoolSize the new core size
     * @throws IllegalArgumentException if {@code corePoolSize < 0}
     *         or {@code corePoolSize} is greater than the {@linkplain
     *         #getMaximumPoolSize() maximum pool size}
     * @see #getCorePoolSize
     */
    public function setCorePoolSize(int $corePoolSize): void
    {
        if ($corePoolSize < 0 || $this->maximumPoolSize < $corePoolSize) {
            throw new \Exception("IllegalArgument");
        }
        $delta = $corePoolSize - $this->corePoolSize;
        $this->corePoolSize = $corePoolSize;
        if (self::workerCountOf($this->ctl->get()) > $corePoolSize) {
            $this->interruptIdleWorkers();
        } elseif ($delta > 0) {
            // We don't really know how many new threads are "needed".
            // As a heuristic, prestart enough new workers (up to new
            // core size) to handle the current number of tasks in
            // queue, but stop if queue becomes empty while doing so.
            $k = min($delta, $this->workQueue->size());
            while ($k-- > 0 && $this->addWorker(null, true)) {
                if ($this->workQueue->isEmpty()) {
                    break;
                }
            }
        }
    }

    /**
     * Returns the core number of threads.
     *
     * @return the core number of threads
     * @see #setCorePoolSize
     */
    public function getCorePoolSize(): int
    {
        return $this->corePoolSize;
    }

    /**
     * Starts a core thread, causing it to idly wait for work. This
     * overrides the default policy of starting core threads only when
     * new tasks are executed. This method will return {@code false}
     * if all core threads have already been started.
     *
     * @return {@code true} if a thread was started
     */
    public function prestartCoreThread(): bool
    {
        return self::workerCountOf($this->ctl->get()) < $this->corePoolSize &&
            $this->addWorker(null, true);
    }

    /**
     * Same as prestartCoreThread except arranges that at least one
     * thread is started even if corePoolSize is 0.
     */
    public function ensurePrestart(): void
    {
        $wc = self::workerCountOf($this->ctl->get());
        if ($wc < $this->corePoolSize) {
            $this->addWorker(null, true);
        } elseif ($wc == 0) {
            $this->addWorker(null, false);
        }
    }

    /**
     * Starts all core threads, causing them to idly wait for work. This
     * overrides the default policy of starting core threads only when
     * new tasks are executed.
     *
     * @return the number of threads started
     */
    public function prestartAllCoreThreads(): int
    {
        $n = 0;
        while ($this->addWorker(null, true)) {
            ++$n;
        }
        return $n;
    }

    /**
     * Returns true if this pool allows core threads to time out and
     * terminate if no tasks arrive within the keepAlive time, being
     * replaced if needed when new tasks arrive. When true, the same
     * keep-alive policy applying to non-core threads applies also to
     * core threads. When false (the default), core threads are never
     * terminated due to lack of incoming tasks.
     *
     * @return {@code true} if core threads are allowed to time out,
     *         else {@code false}
     *
     * @since 1.6
     */
    public function allowsCoreThreadTimeOut(): bool
    {
        return $this->allowCoreThreadTimeOut;
    }

    /**
     * Sets the policy governing whether core threads may time out and
     * terminate if no tasks arrive within the keep-alive time, being
     * replaced if needed when new tasks arrive. When false, core
     * threads are never terminated due to lack of incoming
     * tasks. When true, the same keep-alive policy applying to
     * non-core threads applies also to core threads. To avoid
     * continual thread replacement, the keep-alive time must be
     * greater than zero when setting {@code true}. This method
     * should in general be called before the pool is actively used.
     *
     * @param value {@code true} if should time out, else {@code false}
     * @throws IllegalArgumentException if value is {@code true}
     *         and the current keep-alive time is not greater than zero
     *
     * @since 1.6
     */
    public function allowCoreThreadTimeOut(bool $value): void
    {
        if ($value && $this->keepAliveTime <= 0) {
            throw new \Exception("Core threads must have nonzero keep alive times");
        }
        if ($value !== $this->allowCoreThreadTimeOut) {
            $this->allowCoreThreadTimeOut = $value;
            if ($value) {
                $this->interruptIdleWorkers();
            }
        }
    }

    /**
     * Sets the maximum allowed number of threads. This overrides any
     * value set in the constructor. If the new value is smaller than
     * the current value, excess existing threads will be
     * terminated when they next become idle.
     *
     * @param maximumPoolSize the new maximum
     * @throws IllegalArgumentException if the new maximum is
     *         less than or equal to zero, or
     *         less than the {@linkplain #getCorePoolSize core pool size}
     * @see #getMaximumPoolSize
     */
    public function setMaximumPoolSize(int $maximumPoolSize): void
    {
        if ($maximumPoolSize <= 0 || $maximumPoolSize < $this->corePoolSize) {
            throw new \Exception("IllegalArgument");
        }
        $this->maximumPoolSize = $maximumPoolSize;
        if (self::workerCountOf($this->ctl->get()) > $maximumPoolSize) {
            $this->interruptIdleWorkers();
        }
    }

    /**
     * Returns the maximum allowed number of threads.
     *
     * @return the maximum allowed number of threads
     * @see #setMaximumPoolSize
     */
    public function getMaximumPoolSize(): int
    {
        return $this->maximumPoolSize;
    }

    /**
     * Sets the thread keep-alive time, which is the amount of time
     * that threads may remain idle before being terminated.
     * Threads that wait this amount of time without processing a
     * task will be terminated if there are more than the core
     * number of threads currently in the pool, or if this pool
     * {@linkplain #allowsCoreThreadTimeOut() allows core thread timeout}.
     * This overrides any value set in the constructor.
     *
     * @param time the time to wait.  A time value of zero will cause
     *        excess threads to terminate immediately after executing tasks.
     * @param unit the time unit of the {@code time} argument
     * @throws IllegalArgumentException if {@code time} less than zero or
     *         if {@code time} is zero and {@code allowsCoreThreadTimeOut}
     * @see #getKeepAliveTime(TimeUnit)
     */
    public function setKeepAliveTime(int $time, string $unit): void
    {
        if ($time < 0) {
            throw new \Exception("IllegalArgument");
        }
        if ($time == 0 && $this->allowsCoreThreadTimeOut()) {
            throw new \Exception("Core threads must have nonzero keep alive times");
        }
        $keepAliveTime = TimeUnit::toNanos($time, $unit);
        $delta = $keepAliveTime - $this->keepAliveTime;
        $this->keepAliveTime = $keepAliveTime;
        if ($delta < 0) {
            $this->interruptIdleWorkers();
        }
    }

    public function setScopeArguments(...$args)
    {
        $this->scopeArguments = $args;
    }

    public function getScopeArguments()
    {
        return $this->scopeArguments;
    }

    public function getQueue()
    {
        return $this->workQueue;
    }

    public function remove(RunnableInterface $task): bool
    {
        $removed = $this->workQueue->remove($task);
        $this->tryTerminate(); // In case SHUTDOWN and now empty
        return $removed;
    }

    /**
     * Returns the current number of threads in the pool.
     *
     * @return the number of threads
     */
    public function getPoolSize(): int
    {
        $this->mainLock->lock();
        try {
            // Remove rare and surprising possibility of
            // isTerminated() && getPoolSize() > 0
            return self::runStateAtLeast($this->ctl->get(), self::TIDYING) ? 0
                : count($this->workers);
        } finally {
            $this->mainLock->unlock();
        }
    }

    /**
     * Returns the largest number of threads that have ever
     * simultaneously been in the pool.
     *
     * @return the number of threads
     */
    public function getLargestPoolSize(): int
    {
        $this->mainLock->lock();
        try {
            return $this->largestPoolSize;
        } finally {
            $this->mainLock->unlock();
        }
    }

    /**
     * Method invoked prior to executing the given Runnable in the
     * given thread.  This method is invoked by thread {@code t} that
     * will execute task {@code r}, and may be used to re-initialize
     * ThreadLocals, or to perform logging.
     *
     * <p>This implementation does nothing, but may be customized in
     * subclasses. Note: To properly nest multiple overridings, subclasses
     * should generally invoke {@code super.beforeExecute} at the end of
     * this method.
     *
     * @param t the thread that will run task {@code r}
     * @param r the task that will be executed
     */
    protected function beforeExecute(ThreadInterface $w, $r): void
    {
    }

    protected function afterExecute($r, ?\Throwable $t): void
    {
    }

    /**
     * Method invoked when the Executor has terminated.  Default
     * implementation does nothing. Note: To properly nest multiple
     * overridings, subclasses should generally invoke
     * {@code super.terminated} within this method.
     */
    protected function terminated(): void
    {
    }
}
