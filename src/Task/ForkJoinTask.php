<?php

namespace Concurrent\Task;

use Concurrent\{
    FutureInterface,
    ThreadInterface
};
use Concurrent\Executor\ForkJoinPool;
use Concurrent\Lock\{
    LockInterface,
    NotificationInterface
};
use Concurrent\Worker\ForkJoinWorker;

abstract class ForkJoinTask implements FutureInterface
{
    public static $status;                  // accessed directly by pool and workers
    public static $result;
    public static $fork;
    private static $notification;
    public const DONE_MASK   = -268435456;  //0xf0000000;  // mask out non-completion bits
    public const NORMAL      = -268435456;  //0xf0000000;  // must be negative
    public const CANCELLED   = -1073741824; //0xc0000000;  // must be < NORMAL
    public const EXCEPTIONAL = -2147483648; //0x80000000;  // must be < CANCELLED
    public const SIGNAL      = 0x00010000;  // must be >= 1 << 16
    public const SMASK       = 0x0000ffff;  // short bits for tags

    //just for testing purposes, to check equality
    public $xid;    
    private $mainLock;

    public function __construct()
    {
        $this->xid = md5(microtime() . rand());
        self::$status->set($this->xid, ['status' => 0]);
        $this->mainLock = new \Swoole\Lock(SWOOLE_MUTEX);
    }

    public static function registerNotification(NotificationInterface $notification): void
    {
        self::$notification = $notification;
    }

    public static function registerStatus(\Swoole\Table $status): void
    {
        self::$status = $status;
    }

    public static function registerResult(\Swoole\Table $result): void
    {
        self::$result = $result;
    }

    public static function registerFork(\Swoole\Table $fork): void
    {
        self::$fork = $fork;
    }

    public function getXid(): string
    {
        return $this->xid;
    }

    public function equals($obj): bool
    {
        return $this->xid == $obj->xid;
    }

    public function __serialize(): array
    {
        return [
            'xid' => $this->xid
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
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
            if (($s = self::$status->get($this->xid, 'status')) < 0) {
                return $s;
            }
            if ($s == self::$status->get($this->xid, 'status')) {
                self::$status->set($this->xid, ['status' => $s | $completion]);
                if ($this->uRShift($s, 16) !== 0) {
                    self::$notification->notifyAll();
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

    /**
     * Primary execution method for stolen tasks. Unless done, calls
     * exec and records status if completed, but doesn't wait for
     * completion otherwise.
     *
     * @return status on exit from this method
     */
    public function doExec(ThreadInterface $worker, ...$args): int
    {
        $s = 0;
        $completed = false;
        if (($s = self::$status->get($this->xid, 'status')) >= 0) {
            try {
                $completed = $this->exec($worker, ...$args);
            } catch (\Throwable $rex) {
                return $this->setExceptionalCompletion($rex);
            }
            if ($completed) {
                $s = $this->setCompletion(self::NORMAL);
            }
        }
        return $s;
    }

    /**
     * If not done, sets SIGNAL status and performs Object.wait(timeout).
     * This task may or may not be done on exit. Ignores interrupts.
     *
     * @param timeout in milliseconds
     */
    public function internalWait(int $timeout): void
    {
        $s = 0;
        if (($s = self::$status->get($this->xid, 'status')) >= 0) {
            self::$status->set($this->xid, ['status' => $s | self::SIGNAL]);
            //@TODO lock?
            if ($this->status >= 0) {
                $micro = round($timeout * 1000);
                if ($micro > 1000000) {
                    sleep(round($timeout / 1000));
                } else {
                    usleep($micro);
                }
            } else {
                self::$notification->notifyAll();
            }
        }
    }

    /**
     * Blocks a non-worker-thread until completion.
     * @return status upon completion
     */
    private function externalAwaitDone(?ThreadInterface $worker, ...$args): int
    {
        $s = ForkJoinPool::$common->tryExternalUnpush($this) ? $this->doExec($worker, ...$args) : 0;

        if ($s >= 0 && ($s = self::$status->get($this->xid, 'status')) >= 0) {
            $interrupted = false;
       
            do {    
                if (self::$status->get($this->xid, 'status') === $s) {
                    self::$status->set($this->xid, ['status' => $s | self::SIGNAL]);
                    $this->mainLock->tryLock();
                    try {
                        if (self::$status->get($this->xid, 'status') >= 0) {
                            sleep(0); //?
                        } else {
                            self::$notification->notifyAll();
                        }
                    } finally {
                        $this->mainLock->unlock();
                    }
                }
            } while (($s = self::$status->get($this->xid, 'status')) >= 0);
        }
        return $s;
    }

    /**
     * Blocks a non-worker-thread until completion or interruption.
     */
    private function externalInterruptibleAwaitDone(ThreadInterface $worker): int
    {
        $s = 0;
        if ($worker->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        if (($s = self::$status->get($this->xid, 'status')) >= 0 &&
            ($s = ForkJoinPool::$common->tryExternalUnpush($this) ? $this->doExec($worker) : 0) >= 0) {
            while (($s = self::$status->get($this->xid, 'status')) >= 0) {
                if (self::$status->get($this->xid, 'status') == $s) {
                    self::$status->set($this->xid, ['status' => $s | self::SIGNAL]);
                    $this->mainLock->tryLock();
                    try {
                        if (self::$status->get($this->xid, 'status') >= 0) {
                            sleep(0); //?
                        } else {
                            self::$notification->notifyAll();
                        }
                    } finally {
                        $this->mainLock->unlock();
                    }
                }
            }
        }
        return $s;
    }

     /**
     * Implementation for join, get, quietlyJoin. Directly handles
     * only cases of already-completed, external wait, and
     * unfork+exec.  Others are relayed to ForkJoinPool.awaitJoin.
     *
     * @return status upon completion
     */
    private function doJoin(?ThreadInterface $worker): int
    {
        $s = 0; 
        $t = null; 
        $wt = null;
        $w = null;
        if (($s = self::$status->get($this->xid, 'status')) < 0) {
            $res = $s;
        } else {
            if ($worker !== null && self::$fork->get((string) getmypid()) !== false) {
                $wt = $worker;
                $w = $wt->workQueue;        
                if ($w->tryUnpush($this) && ($s = $this->doExec($worker)) < 0) {
                    $res = $s;
                } else {
                    $res = $wt->pool->awaitJoin($w, $this, 0);
                }
            } else {
                $res = $this->externalAwaitDone(null);
            }
        }
        return $res;
    }

    /**
     * Implementation for invoke, quietlyInvoke.
     *
     * @return status upon completion
     */
    private function doInvoke(?ThreadInterface $worker, ...$args): int
    {
        $s = 0;
        $t = null;
        $wt = null;
        return (($s = $this->doExec($worker)) < 0) ? $s :
        ((self::$fork->get((string) getmypid()) !== false) ?
            (($wt = $worker)->pool->awaitJoin($wt->workQueue, $worker, $this, 0)) :
        $this->externalAwaitDone(null, ...$args));
    }

    //@TODO exception handling

    /**
     * Arranges to asynchronously execute this task in the pool the
     * current task is running in, if applicable, or using the {@link
     * ForkJoinPool#commonPool()} if not {@link #inForkJoinPool}.  While
     * it is not necessarily enforced, it is a usage error to fork a
     * task more than once unless it has completed and been
     * reinitialized.  Subsequent modifications to the state of this
     * task or any data it operates on are not necessarily
     * consistently observable by any thread other than the one
     * executing it unless preceded by a call to {@link #join} or
     * related methods, or a call to {@link #isDone} returning {@code
     * true}.
     *
     * @return {@code this}, to simplify usage
     */
    public function fork(?ThreadInterface $worker): ForkJoinTask
    {
        if ($worker !== null && self::$fork->get((string) getmypid()) !== false) {
            $worker->workQueue->push($this);
        } else {
            ForkJoinPool::$common->externalPush($this);
        }
        return $this;
        /*$internal = false;
        if ($internal = ($worker !== null && self::$fork->get((string) getmypid()) !== false)) {
            $q = $worker->workQueue;
            $p = $worker->pool;
        } else {
            $q = ($p = ForkJoinPool::$common)->externalSubmissionQueue();
        }
        $q->push($this, $p, $internal);
        return $this;*/
    }

    /**
     * Returns the result of the computation when it {@link #isDone is
     * done}.  This method differs from {@link #get()} in that
     * abnormal completion results in {@code RuntimeException} or
     * {@code Error}, not {@code ExecutionException}, and that
     * interrupts of the calling thread do <em>not</em> cause the
     * method to abruptly return by throwing {@code
     * InterruptedException}.
     *
     * @return the computed result
     */
    public function join(?ThreadInterface $worker = null, ...$args)
    {
        $s = 0;

        if (($s = $this->doJoin($worker, ...$args) & self::DONE_MASK) !== self::NORMAL) {
            //reportException(s);
            //@TODO - implement exception handling
        }
        return $this->getRawResult();
    }

    /**
     * Commences performing this task, awaits its completion if
     * necessary, and returns its result, or throws an (unchecked)
     * {@code RuntimeException} or {@code Error} if the underlying
     * computation did so.
     *
     * @return the computed result
     */
    public function invoke(ThreadInterface $worker, ...$args)
    {
        $s = 0;
        if (($s = $this->doInvoke($worker, ...$args) & self::DONE_MASK) !== self::NORMAL) {
            //reportException(s);
            //@TODO exception handling
        }
        return $this->getRawResult();
    }

    /**
     * Forks the given tasks, returning when {@code isDone} holds for
     * each task or an (unchecked) exception is encountered, in which
     * case the exception is rethrown. If more than one task
     * encounters an exception, then this method throws any one of
     * these exceptions. If any task encounters an exception, others
     * may be cancelled. However, the execution status of individual
     * tasks is not guaranteed upon exceptional return. The status of
     * each task may be obtained using {@link #getException()} and
     * related methods to check if they have been cancelled, completed
     * normally or exceptionally, or left unprocessed.
     *
     * @param tasks the tasks
     * @throws NullPointerException if any task is null
     */
    public static function invokeAll(ThreadInterface $worker, ...$tasks): void
    {
        $ex = null;
        $last = count($tasks) - 1;
        for ($i = $last; $i >= 0; --$i) {
            $t = $tasks[$i];
            if ($t == null) {
                if ($ex == null) {
                    $ex = new \Exception("NullPointer");
                }
            } elseif ($i !== 0) {
                $t->fork($worker);
            } elseif ($t->doInvoke($worker) < self::NORMAL && $ex === null) {
                $ex = $t->getException();
            }
        }
        for ($i = 1; $i <= $last; ++$i) {
            $t = $tasks[$i];
            if ($t !== null) {
                if ($ex !== null) {
                    $t->cancel(false);
                } elseif ($t->doJoin($worker) < self::NORMAL) {
                    $ex = $t->getException();
                }
            }
        }
        if ($ex !== null) {
            $this->rethrow($ex);
        }
    }

    /**
     * Attempts to cancel execution of this task. This attempt will
     * fail if the task has already completed or could not be
     * cancelled for some other reason. If successful, and this task
     * has not started when {@code cancel} is called, execution of
     * this task is suppressed. After this method returns
     * successfully, unless there is an intervening call to {@link
     * #reinitialize}, subsequent calls to {@link #isCancelled},
     * {@link #isDone}, and {@code cancel} will return {@code true}
     * and calls to {@link #join} and related methods will result in
     * {@code CancellationException}.
     *
     * <p>This method may be overridden in subclasses, but if so, must
     * still ensure that these properties hold. In particular, the
     * {@code cancel} method itself must not throw exceptions.
     *
     * <p>This method is designed to be invoked by <em>other</em>
     * tasks. To terminate the current task, you can just return or
     * throw an unchecked exception from its computation method, or
     * invoke {@link #completeExceptionally(Throwable)}.
     *
     * @param mayInterruptIfRunning this value has no effect in the
     * default implementation because interrupts are not used to
     * control cancellation.
     *
     * @return {@code true} if this task is now cancelled
     */
    public function cancel(bool $mayInterruptIfRunning): bool
    {
        return ($this->setCompletion(self::CANCELLED) & self::DONE_MASK) === self::CANCELLED;
    }

    public function isDone(): bool
    {
        return self::$status->get($this->xid, 'status') < 0;
    }

    public function isCancelled(): bool
    {
        return (self::$status->get($this->xid, 'status') & self::DONE_MASK) === self::CANCELLED;
    }

    /**
     * Returns {@code true} if this task threw an exception or was cancelled.
     *
     * @return {@code true} if this task threw an exception or was cancelled
     */
    public function isCompletedAbnormally(): bool
    {
        return self::$status->get($this->xid, 'status') < self::NORMAL;
    }

    /**
     * Returns {@code true} if this task completed without throwing an
     * exception and was not cancelled.
     *
     * @return {@code true} if this task completed without throwing an
     * exception and was not cancelled
     */
    public function isCompletedNormally(): bool
    {
        return (self::$status->get($this->xid, 'status') & self::DONE_MASK) === self::NORMAL;
    }

    /**
     * Returns the exception thrown by the base computation, or a
     * {@code CancellationException} if cancelled, or {@code null} if
     * none or if the method has not yet completed.
     *
     * @return the exception, or {@code null} if none
     */
    public function getException(): ?\Throwable
    {
        /*int s = status & DONE_MASK;
        return ((s >= NORMAL)    ? null :
                (s == CANCELLED) ? new CancellationException() :
                getThrowableException());*/
        return null;
    }

    /**
     * Completes this task abnormally, and if not already aborted or
     * cancelled, causes it to throw the given exception upon
     * {@code join} and related operations. This method may be used
     * to induce exceptions in asynchronous tasks, or to force
     * completion of tasks that would not otherwise complete.  Its use
     * in other situations is discouraged.  This method is
     * overridable, but overridden versions must invoke {@code super}
     * implementation to maintain guarantees.
     *
     * @param ex the exception to throw. If this exception is not a
     * {@code RuntimeException} or {@code Error}, the actual exception
     * thrown will be a {@code RuntimeException} with cause {@code ex}.
     */
    public function completeExceptionally(\Throwable $ex): void
    {
        /*setExceptionalCompletion((ex instanceof RuntimeException) ||
                                 (ex instanceof Error) ? ex :
                                 new RuntimeException(ex));*/
    }

    /**
     * Completes this task, and if not already aborted or cancelled,
     * returning the given value as the result of subsequent
     * invocations of {@code join} and related operations. This method
     * may be used to provide results for asynchronous tasks, or to
     * provide alternative handling for tasks that would not otherwise
     * complete normally. Its use in other situations is
     * discouraged. This method is overridable, but overridden
     * versions must invoke {@code super} implementation to maintain
     * guarantees.
     *
     * @param value the result value for this task
     */
    public function complete($value): void
    {
        try {
            $this->setRawResult($value);
        } catch (\Throwable $rex) {
            $this->setExceptionalCompletion($rex);
            return;
        }
        $this->setCompletion(self::NORMAL);
    }

    /**
     * Completes this task normally without setting a value. The most
     * recent value established by {@link #setRawResult} (or {@code
     * null} by default) will be returned as the result of subsequent
     * invocations of {@code join} and related operations.
     *
     * @since 1.8
     */
    public function quietlyComplete(): void
    {
        $this->setCompletion(self::NORMAL);
    }

    /**
     * Waits if necessary for the computation to complete, and then
     * retrieves its result.
     *
     * @return the computed result
     * @throws CancellationException if the computation was cancelled
     * @throws ExecutionException if the computation threw an
     * exception
     * @throws InterruptedException if the current thread is not a
     * member of a ForkJoinPool and was interrupted while waiting
     */
    public function get(?ThreadInterface $worker)
    {
        $s = (self::$fork->get((string) getmypid()) !== false) ?
            $this->doJoin($worker) : $this->externalInterruptibleAwaitDone($worker);
        $ex = null;
        if (($s &= self::DONE_MASK) == self::CANCELLED) {
            throw new \Exception("Cancellation");
        }
        if ($s === self::EXCEPTIONAL && ($ex = $this->getThrowableException()) !== null) {
            throw $ex;
        }
        return $this->getRawResult();
    }

    /**
     * Joins this task, without returning its result or throwing its
     * exception. This method may be useful when processing
     * collections of tasks when some have been cancelled or otherwise
     * known to have aborted.
     */
    public function quietlyJoin(ThreadInterface $thread): void
    {
        $this->doJoin($thread);
    }

    /**
     * Commences performing this task and awaits its completion if
     * necessary, without returning its result or throwing its
     * exception.
     */
    public function quietlyInvoke(ThreadInterface $worker): void
    {
        $this->doInvoke($worker);
    }

    /**
     * Possibly executes tasks until the pool hosting the current task
     * {@link ForkJoinPool#isQuiescent is quiescent}. This method may
     * be of use in designs in which many tasks are forked, but none
     * are explicitly joined, instead executing them until all are
     * processed.
     */
    public static function helpQuiesce(ThreadInterface $worker): void
    {
        if ($worker !== null && self::$fork->get((string) getmypid()) !== false) {
            $worker->pool->helpQuiescePool($worker->workQueue, $worker);
        } else {
            ForkJoinPool::quiesceCommonPool();
        }
    }

    /**
     * Resets the internal bookkeeping state of this task, allowing a
     * subsequent {@code fork}. This method allows repeated reuse of
     * this task, but only if reuse occurs when this task has either
     * never been forked, or has been forked, then completed and all
     * outstanding joins of this task have also completed. Effects
     * under any other usage conditions are not guaranteed.
     * This method may be useful when executing
     * pre-constructed trees of subtasks in loops.
     *
     * <p>Upon completion of this method, {@code isDone()} reports
     * {@code false}, and {@code getException()} reports {@code
     * null}. However, the value returned by {@code getRawResult} is
     * unaffected. To clear this value, you can invoke {@code
     * setRawResult(null)}.
     */
    public function reinitialize(): void
    {
        if ((self::$status->get($this->xid, 'status') & self::DONE_MASK) === self::EXCEPTIONAL) {
            $this->clearExceptionalCompletion();
        } else {
            self::$status->set($this->xid, ['status' => 0]);
        }
    }

    /**
     * Returns the pool hosting the current task execution, or null
     * if this task is executing outside of any ForkJoinPool.
     *
     * @see #inForkJoinPool
     * @return the pool, or {@code null} if none
     */
    public static function getPool(?ThreadInterface $worker): ForkJoinPool
    {
        return ($worker !== null && self::$fork->get((string) getmypid()) !== false) ? $worker->pool : null;
    }

    /**
     * Returns {@code true} if the current thread is a {@link
     * ForkJoinWorker} executing as a ForkJoinPool computation.
     *
     * @return {@code true} if the current thread is a {@link
     * ForkJoinWorker} executing as a ForkJoinPool computation,
     * or {@code false} otherwise
     */
    public static function inForkJoinPool(?ThreadInterface $worker): bool
    {
        return $worker instanceof ForkJoinWorker && self::$fork->get((string) getmypid()) !== false;
    }

    /**
     * Tries to unschedule this task for execution. This method will
     * typically (but is not guaranteed to) succeed if this task is
     * the most recently forked task by the current thread, and has
     * not commenced executing in another thread.  This method may be
     * useful when arranging alternative local processing of tasks
     * that could have been, but were not, stolen.
     *
     * @return {@code true} if unforked
     */
    public function tryUnfork(?ThreadInterface $worker): bool
    {
        return $worker !== null && self::$fork->get((string) getmypid()) !== false ?
               $worker->workQueue->tryUnpush($this) :
               ForkJoinPool::$common->tryExternalUnpush($this);
    }

    /**
     * Returns an estimate of the number of tasks that have been
     * forked by the current worker thread but not yet executed. This
     * value may be useful for heuristic decisions about whether to
     * fork other tasks.
     *
     * @return the number of tasks
     */
    public static function getQueuedTaskCount(?ThreadInterface $worker): int
    {
        $q = null;
        if ($worker !== null && self::$fork->get((string) getmypid()) !== false) {
            $q = $worker->workQueue;
        } else {
            $q = ForkJoinPool::commonSubmitterQueue($worker);
        }
        return ($q == null) ? 0 : $q->queueSize();
    }

    /**
     * Returns an estimate of how many more locally queued tasks are
     * held by the current worker thread than there are other worker
     * threads that might steal them, or zero if this thread is not
     * operating in a ForkJoinPool. This value may be useful for
     * heuristic decisions about whether to fork other tasks. In many
     * usages of ForkJoinTasks, at steady state, each worker should
     * aim to maintain a small constant surplus (for example, 3) of
     * tasks, and to process computations locally if this threshold is
     * exceeded.
     *
     * @return the surplus number of tasks, which may be negative
     */
    public static function getSurplusQueuedTaskCount(ThreadInterface $thread): int
    {
        return ForkJoinPool::getSurplusQueuedTaskCount($thread);
    }

    // Extension methods

    /**
     * Returns the result that would be returned by {@link #join}, even
     * if this task completed abnormally, or {@code null} if this task
     * is not known to have been completed.  This method is designed
     * to aid debugging, as well as to support extensions. Its use in
     * any other context is discouraged.
     *
     * @return the result, or {@code null} if not completed
     */
    abstract public function getRawResult();

    /**
     * Forces the given value to be returned as a result.  This method
     * is designed to support extensions, and should not in general be
     * called otherwise.
     *
     * @param value the value
     */
    abstract public function setRawResult($value): void;

    /**
     * Immediately performs the base action of this task and returns
     * true if, upon return from this method, this task is guaranteed
     * to have completed normally. This method may return false
     * otherwise, to indicate that this task is not necessarily
     * complete (or is not known to be complete), for example in
     * asynchronous actions that require explicit invocations of
     * completion methods. This method may also throw an (unchecked)
     * exception to indicate abnormal exit. This method is designed to
     * support extensions, and should not in general be called
     * otherwise.
     *
     * @return {@code true} if this task is known to have completed normally
     */
    abstract public function exec(ThreadInterface $thread, ...$args): bool;

    /**
     * Returns, but does not unschedule or execute, a task queued by
     * the current thread but not yet executed, if one is immediately
     * available. There is no guarantee that this task will actually
     * be polled or executed next. Conversely, this method may return
     * null even if a task exists but cannot be accessed without
     * contention with other threads.  This method is designed
     * primarily to support extensions, and is unlikely to be useful
     * otherwise.
     *
     * @return the next task, or {@code null} if none are available
     */
    public static function peekNextLocalTask(?ThreadInterface $worker): ?ForkJoinTask
    {
        $q = null;
        if ($worker !== null && self::$fork->get((string) getmypid()) !== false) {
            $q = $worker->workQueue;
        } else {
            $q = ForkJoinPool::commonSubmitterQueue($worker);
        }
        return ($q == null) ? null : $q->peek();
    }

    /**
     * Unschedules and returns, without executing, the next task
     * queued by the current thread but not yet executed, if the
     * current thread is operating in a ForkJoinPool.  This method is
     * designed primarily to support extensions, and is unlikely to be
     * useful otherwise.
     *
     * @return the next task, or {@code null} if none are available
     */
    public static function pollNextLocalTask(?ThreadInterface $worker): ?ForkJoinTask
    {
        return ($worker !== null && self::$fork->get((string) getmypid()) !== false) ? $worker->workQueue->nextLocalTask() : null;
    }

    /**
     * If the current thread is operating in a ForkJoinPool,
     * unschedules and returns, without executing, the next task
     * queued by the current thread but not yet executed, if one is
     * available, or if not available, a task that was forked by some
     * other thread, if available. Availability may be transient, so a
     * {@code null} result does not necessarily imply quiescence of
     * the pool this task is operating in.  This method is designed
     * primarily to support extensions, and is unlikely to be useful
     * otherwise.
     *
     * @return a task, or {@code null} if none are available
     */
    public static function pollTask(?ThreadInterface $worker): ?ForkJoinTask
    {
        return ($worker !== null && self::$fork->get((string) getmypid()) !== false) ? $worker->pool->nextTaskFor($wt->workQueue) : null;
    }

    // tag operations

    private function toShort(int $value): int
    {
        $short = $value & 0xFFFF; // Mask to 16 bits
        if ($short & 0x8000) { // Check if the number is 'negative'
            $short = -((~$short & 0xFFFF) + 1); // 2's complement to negative
        }
        return $short;
    }
    /**
     * Returns the tag for this task.
     *
     * @return the tag for this task
     * @since 1.8
     */
    public function getForkJoinTaskTag(): int
    {
        return $this->toShort(self::$status->get($this->xid, 'status'));
    }

    /**
     * Atomically sets the tag value for this task.
     *
     * @param tag the tag value
     * @return the previous value of the tag
     * @since 1.8
     */
    public function setForkJoinTaskTag(int $tag): int
    {
        $s = self::$status->get($this->xid, 'status');
        self::$status->set($this->xid, ['status' => ($s & ~self::SMASK) | ($tag & self::SMASK)]);
        return $this->toShort($s);
    }

    /**
     * Atomically conditionally sets the tag value for this task.
     * Among other applications, tags can be used as visit markers
     * in tasks operating on graphs, as in methods that check: {@code
     * if (task.compareAndSetForkJoinTaskTag((short)0, (short)1))}
     * before processing, otherwise exiting because the node has
     * already been visited.
     *
     * @param e the expected tag value
     * @param tag the new tag value
     * @return {@code true} if successful; i.e., the current value was
     * equal to e and is now tag.
     * @since 1.8
     */
    public function compareAndSetForkJoinTaskTag(int $e, int $tag): bool
    {
        while (true) {
            if ($this->toShort($s = self::$status->get($this->xid, 'status')) !== $e) {
                return false;
            }
            if (self::$status->get($this->xid, 'status') == $s) {
                self::$status->set($this->xid, ['status' => ($s & ~self::SMASK) | ($tag & self::SMASK)]);
                return true;
            }
        }
    }

     /**
     * Returns a new {@code ForkJoinTask} that performs the {@code run}
     * method of the given {@code Runnable} as its action, and returns
     * a null result upon {@link #join}.
     *
     * @param runnable the runnable action
     * @return the task
     */
    public static function adapt(RunnableInterface | callable $action, &$result = null)
    {
        if ($action instanceof RunnableInterface && $result == null) {
            return new AdaptedRunnableAction($action);
        } elseif ($action instanceof RunnableInterface) {
            return new AdaptedRunnable($action, $result); 
        } elseif (is_callable($action)) {
            return new AdaptedCallable($action);
        }
    }

    public static function cancelIgnoringExceptions(?ForkJoinTask $t): void
    {
        if ($t !== null && self::$status->get($t->getXid(), 'status') >= 0) {
            try {
                $t->cancel(false);
            } catch (\Throwable $ignore) {
            }
        }
    }
}
