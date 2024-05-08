<?php

namespace Concurrent\Task;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableFutureInterface,
    RunnableInterface,
    ThreadInterface,
    TimeUnit
};
use Concurrent\Worker\InterruptibleProcess;
use Concurrent\Lock\LockSupport;

class FutureTask implements RunnableFutureInterface
{
    /**
     * The run state of this task, initially NEW.  The run state
     * transitions to a terminal state only in methods set,
     * setException, and cancel.  During completion, state may take on
     * transient values of COMPLETING (while outcome is being set) or
     * INTERRUPTING (only while interrupting the runner to satisfy a
     * cancel(true)). Transitions from these intermediate to final
     * states use cheaper ordered/lazy writes because values are unique
     * and cannot be further modified.s
     *
     * Possible state transitions:
     * NEW -> COMPLETING -> NORMAL
     * NEW -> COMPLETING -> EXCEPTIONAL
     * NEW -> CANCELLED
     * NEW -> INTERRUPTING -> INTERRUPTED
     */
    private static $state;
    private const NEW          = 0;
    private const COMPLETING   = 1;
    private const NORMAL       = 2;
    private const EXCEPTIONAL  = 3;
    private const CANCELLED    = 4;
    private const INTERRUPTING = 5;
    private const INTERRUPTED  = 6;

    /** The underlying callable; nulled out after running */
    public $callable;

    /** The result to return or exception to throw from get() */
    protected $outcome; // non-volatile, protected by state reads/writes
    
    /** The thread running the callable; CASed during run() */
    protected static $runner;

    /** Treiber stack of waiting threads */
    public static $waiters;

    /** Stack head */
    public static $stack;

    public const DEFAULT_MAX_WAITERS = 2056;

    /**
     * Returns result or throws exception for completed task.
     *
     * @param s completed state value
     */
    private function report(int $s)
    {
        if ($s === self::NORMAL) {
            return $this->outcome;
        }
        if ($s >= self::CANCELLED) {
            throw new \Exception("Cancellation");
        }
        throw new \Exception($this->outcome->getMessage());
    }

    public $xid;

    private static $initialized = false;

    /**
     * Creates a {@code FutureTask} that will, upon running, execute the
     * given {@code Runnable}, and arrange that {@code get} will return the
     * given result on successful completion.
     *
     * @param runnable the runnable task
     */
    public function __construct(RunnableInterface | callable $op, ?int $maxWaiters = self::DEFAULT_MAX_WAITERS)
    {
        $this->xid = md5(microtime() . rand());
        $this->callable = $op;

        if (!self::$initialized) {
            self::$initialized = true;

            self::$state = new \Swoole\Table($maxWaiters);
            self::$state->column('xid', \Swoole\Table::TYPE_STRING, 32); 
            self::$state->column('state', \Swoole\Table::TYPE_INT);             
            self::$state->create();

            self::$stack = new \Swoole\Table($maxWaiters);
            self::$stack->column('xid', \Swoole\Table::TYPE_STRING, 32); 
            self::$stack->column('stack', \Swoole\Table::TYPE_INT);             
            self::$stack->create();

            self::$waiters = new \Swoole\Table($maxWaiters);
            self::$waiters->column('thread', \Swoole\Table::TYPE_INT);
            self::$waiters->column('next', \Swoole\Table::TYPE_INT);
            self::$waiters->create();

            self::$runner = new \Swoole\Table($maxWaiters);
            self::$runner->column('xid', \Swoole\Table::TYPE_STRING, 32); 
            self::$runner->column('runner', \Swoole\Table::TYPE_INT);             
            self::$runner->create();
        }

        //@TODO. Combine in one table
        self::$state->set($this->xid, ['xid' => $this->xid, 'state' => self::NEW]);
        self::$runner->set($this->xid, ['xid' => $this->xid, 'runner' => -1]);
        self::$stack->set($this->xid, ['xid' => $this->xid, 'stack' => -1]);
    }

    public function getXid(): string
    {
        return $this->xid;
    }

    public function equals($obj = null): bool
    {
        if ($obj === null || !($obj instanceof FutureTask)) {
            return false;
        }
        return $this->xid === $obj->getXid();
    }

    public function isCancelled(): bool
    {
        return self::$state->get($this->xid, 'state') >= self::CANCELLED;
    }

    public function isDone(): bool
    {
        return self::$state->get($this->xid, 'state') !== self::NEW;
    }

    public function cancel(bool $mayInterruptIfRunning, ?ExecutorServiceInterface $executor = null): bool
    {
        if (self::$state->get($this->xid, 'state') !== self::NEW) {
            self::$state->set($this->xid, ['xid' => $this->xid, 'state' => $mayInterruptIfRunning ? self::INTERRUPTING : self::CANCELLED]);
            return false;
        }
        try {    // in case call to interrupt throws exception
            if ($mayInterruptIfRunning) {
                try {
                    /*if ($this->runner !== null && $this->runner instanceof InterruptibleProcess) {
                        $this->runner->interrupt();
                    }*/
                } finally { // final state
                    self::$state->set($this->xid, ['xid' => $this->xid, 'state' => self::INTERRUPTED]);
                }
            }
        } finally {
            $this->finishCompletion();
        }
        return true;
    }

    /**
     * @throws CancellationException {@inheritDoc}
     */
    public function get(?int $timeout, ?string $unit)
    {
        $s = self::$state->get($this->xid, 'state');
        if ($timeout === null && $unit === null) {            
            if ($s <= self::COMPLETING) {
                $s = $this->awaitDone(false, 0);
            }
            return $this->report($s);
        }
        if ($s <= self::COMPLETING &&
            ($s = $this->awaitDone(true, TimeUnit::toNanos($timeout, $unit))) <= self::COMPLETING) {
            throw new \Exception("Timeout");
        }
        return $this->report($s);
    }

    public function resultNow()
    {
        return match ($this->state()) {    // Future.State
            FutureState::SUCCESS => $this->outcome,
            FutureState::FAILED => throw new \Exception("Task completed with exception"),
            FutureState::CANCELLED => throw new \Exception("Task was cancelled"),
        };
        throw new \Exception("Task has not completed");
    }

    public function exceptionNow(): \Throwable
    {
        return match ($this->state()) {    // Future.State
            FutureState::SUCCESS => throw new \Exception("Task completed with a result"),
            FutureState::FAILED => $this->outcome,
            FutureState::CANCELLED => throw new \Exception("Task was cancelled"),
        };
        throw new \Exception("Task has not completed");
    }

    public function state()
    {
        $s = self::$state->get($this->xid, 'state');
        while ($s == self::COMPLETING) {
            // waiting for transition to NORMAL or EXCEPTIONAL
            usleep(1);
            $s = self::$state->get($this->xid, 'state');
        }
        switch ($s) {
            case self::NORMAL:
                return FutureState::SUCCESS;
            case self::EXCEPTIONAL:
                return FutureState::FAILED;
            case self::CANCELLED:
            case self::INTERRUPTING:
            case self::INTERRUPTED:
                return FutureState::CANCELLED;
            default:
                return FutureState::RUNNING;
        }
    }

    /**
     * Protected method invoked when this task transitions to state
     * {@code isDone} (whether normally or via cancellation). The
     * default implementation does nothing.  Subclasses may override
     * this method to invoke completion callbacks or perform
     * bookkeeping. Note that you can query status inside the
     * implementation of this method to determine whether this task
     * has been cancelled.
     */
    protected function done(): void
    {
    }

    /**
     * Sets the result of this future to the given value unless
     * this future has already been set or has been cancelled.
     *
     * <p>This method is invoked internally by the {@link #run} method
     * upon successful completion of the computation.
     *
     * @param v the value
     */
    public function set($v): void
    {
        if (self::$state->get($this->xid, 'state') == self::NEW) {
            self::$state->set($this->xid, ['xid' => $this->xid, 'state' => self::COMPLETING]);
            $this->outcome = $v;
            self::$state->set($this->xid, ['xid' => $this->xid, 'state' => self::NORMAL]);
            $this->finishCompletion();
        }
    }

    /**
     * Causes this future to report an {@link ExecutionException}
     * with the given throwable as its cause, unless this future has
     * already been set or has been cancelled.
     *
     * <p>This method is invoked internally by the {@link #run} method
     * upon failure of the computation.
     *
     * @param t the cause of failure
     */
    public function setException(\Throwable $t): void{
        if (self::$state->get($this->xid, 'state') == self::NEW) {
            self::$state->set($this->xid, ['xid' => $this->xid, 'state' => self::COMPLETING]);
            $this->outcome = $t;
            self::$state->set($this->xid, ['xid' => $this->xid, 'state' => self::EXCEPTIONAL]);
            $this->finishCompletion();
        }
    }

    public function run(ThreadInterface $worker = null, ...$args): void
    {
        if (self::$state->get($this->xid, 'state') !== self::NEW || self::$runner->get($this->xid, 'runner') !== -1) {
            return;
        } elseif (self::$runner->get($this->xid, 'runner') == -1) {
            self::$runner->set($this->xid, ['xid' => $this->xid, 'runner' => getmypid()]);
        }
        try {
            if (($c = $this->callable) !== null && self::$state->get($this->xid, 'state') === self::NEW) {
                $result = null;
                $ran = false;
                try {
                    if ($c instanceof RunnableInterface) {
                        $c->run($worker, ...$args); 
                    } else {
                        $c();
                    }
                    $ran = true;
                } catch (\Throwable $ex) {
                    $result = null;
                    $ran = false;
                    $this->setException($ex);
                }
                if ($ran) {
                    $this->set($result);
                }
            }
        } finally {
            // runner must be non-null until state is settled to
            // prevent concurrent calls to run()
            self::$runner->set($this->xid, ['xid' => $this->xid, 'runner' => -1]);
            // state must be re-read after nulling runner to prevent
            // leaked interrupts
            if (self::$state->get($this->xid, 'state') >= self::INTERRUPTING) {
                $this->handlePossibleCancellationInterrupt($s);
            }
        }
    }

    /**
     * Executes the computation without setting its result, and then
     * resets this future to initial state, failing to do so if the
     * computation encounters an exception or is cancelled.  This is
     * designed for use with tasks that intrinsically execute more
     * than once.
     *
     * @return {@code true} if successfully run and reset
     */
    public function runAndReset(): bool
    {
        if (self::$state->get($this->xid, 'state') !== self::NEW || self::$runner->get($this->xid, 'runner') !== -1) {
            return false;
        } elseif (self::$runner->get($this->xid, 'runner') === -1) {
            self::$runner->set($this->xid, ['xid' => $this->xid, 'runner' => getmypid()]);
        }
        $ran = false;
        $s = self::$state->get($this->xid, 'state');
        try {
            $c = $this->callable;
            if ($c !== null && $s === self::NEW) {
                try {
                    if ($c instanceof RunnableInterface) {
                        $c->run($process); 
                    } else {
                        $c();
                    }
                    $ran = true;
                } catch (\Throwable $ex) {
                    $this->setException($ex);
                }
            }
        } finally {
            // runner must be non-null until state is settled to
            // prevent concurrent calls to run()
            self::$runner->set($this->xid, ['xid' => $this->xid, 'runner' => -1]);
            // state must be re-read after nulling runner to prevent
            // leaked interrupts
            if ($s >= self::INTERRUPTING) {
                $this->handlePossibleCancellationInterrupt($s);
            }
        }
        return $ran && $s === self::NEW;
    }

    /**
     * Ensures that any interrupt from a possible cancel(true) is only
     * delivered to a task while in run or runAndReset.
     */
    private function handlePossibleCancellationInterrupt(int $s): void
    {
        // It is possible for our interrupter to stall before getting a
        // chance to interrupt us.  Let's spin-wait patiently.
        if ($s === self::INTERRUPTING) {
            while (self::$state->get($this->xid, 'state') === self::INTERRUPTING) {
                //Thread.yield(); // wait out pending interrupt
                usleep(1);
            }
        }

        // assert state == INTERRUPTED;

        // We want to clear any interrupt we may have received from
        // cancel(true).  However, it is permissible to use interrupts
        // as an independent mechanism for a task to communicate with
        // its caller, and there is no way to clear only the
        // cancellation interrupt.
        //
        // Thread.interrupted();
    }

    /**
     * Removes and signals all waiting threads, invokes done(), and
     * nulls out callable.
     */
    private function finishCompletion(): void
    {
        for ($q; ($q = self::$stack->get($this->xid, 'stack')) !== -1;) {
            self::$stack->set($this->xid, ['xid' => $this->xid, 'stack' => -1]);
            for (;;) {
                $qdata = self::$waiters->get((string) $q);
                $t = $qdata['thread'];
                if ($t !== 0 && $t !== -1) {
                    LockSupport::unpark($t, hrtime(true));
                }
                $next = $qdata['next'];
                if ($next === 0 || $next === -1) {
                    break;
                }
                self::$waiters->del((string) $t);
                self::$stack->set($this->xid, ['xid' => $this->xid, 'stack' => $next]);
            }
            break;
        }

        $this->done();

        $this->callable = null;
    }

    /**
     * Awaits completion or aborts on interrupt or timeout.
     *
     * @param timed true if use timed waits
     * @param nanos time to wait, if timed
     * @return state upon completion or at timeout
     */
    private function awaitDone(bool $timed, int $nanos): int
    {
        // The code below is very delicate, to achieve these goals:
        // - call nanoTime exactly once for each call to park
        // - if nanos <= 0L, return promptly without allocation or nanoTime
        // - if nanos == Long.MIN_VALUE, don't underflow
        // - if nanos == Long.MAX_VALUE, and nanoTime is non-monotonic
        //   and we suffer a spurious wakeup, we will do no worse than
        //   to park-spin for a while
        $startTime = 0;    // Special value 0L means not yet parked
        $q = null;
        $queued = false;
        for (;;) {
            $s = self::$state->get($this->xid, 'state');
            if ($s > self::COMPLETING) {
                if ($q !== null) {
                    $pid = $q['thread'];
                    $q['thread'] = -1;
                    self::$waiters->set((string) $pid, $q);
                }
                return $s;
            } elseif ($s == self::COMPLETING) {
                // We may have already promised (via isDone) that we are done
                // so never return empty-handed or throw InterruptedException
                usleep(1);
            }/*else if (Thread.interrupted()) {
                removeWaiter(q);
                throw new InterruptedException();
            }*/
            elseif ($q === null) {
                if ($timed && $nanos <= 0) {
                    return $s;
                }
                $q = ['thread' => -1, 'next' => -1];
            } elseif (!$queued) {
                //queued = WAITERS.weakCompareAndSet(this, q.next = waiters, q);
                $wdata = self::$waiters->get((string) self::$stack->get($this->xid, 'stack'));
                if (self::$stack->get($this->xid, 'stack') === -1 || $wdata === false) {
                    $q['next'] = -1;
                } else {
                    $q['next'] = $wdata['thread'];
                }
                self::$waiters->set((string) $q['thread'], $q);
                self::$stack->set($this->xid, ['xid' => $this->xid, 'stack' => $q['thread']]);
            } elseif ($timed) {
                $parkNanos = 0;
                if ($startTime === 0) { // first time
                    $startTime = hrtime(true);
                    if ($startTime == 0) {
                        $startTime = 1;
                    }
                    $parkNanos = $nanos;
                } else {
                    $elapsed = hrtime(true) - $startTime;
                    if ($elapsed >= $nanos) {
                        $this->removeWaiter($q);
                        return self::$state->get($this->xid, 'state');
                    }
                    $parkNanos = $nanos - $elapsed;
                }
                // nanoTime may be slow; recheck before parking
                if (self::$state->get($this->xid, 'state') < self::COMPLETING) {
                    LockSupport::parkNanos(null, $parkNanos);
                }
            } else {
                LockSupport::park(null);
            }
        }
    }

    /**
     * Tries to unlink a timed-out or interrupted wait node to avoid
     * accumulating garbage.  Internal nodes are simply unspliced
     * without CAS since it is harmless if they are traversed anyway
     * by releasers.  To avoid effects of unsplicing from already
     * removed nodes, the list is retraversed in case of an apparent
     * race.  This is slow when there are a lot of nodes, but we don't
     * expect lists to be long enough to outweigh higher-overhead
     * schemes.
     */
    private function removeWaiter(array $node): void
    {
        if (!empty($node)) {
            $node['thread'] = -1;
            for (;;) {          // restart on removeWaiter race
                for ($pred = null, $q = self::$stack->get($this->xid, 'stack'), $s; $q !== -1; $q = $s) {
                    $qdata = self::$waiters->get((string) $q);
                    if ($qdata !== false) {
                        $next = $qdata['next'];
                        $s = self::$waiters->get((string) $next);
                        if ($qdata['thread'] !== 0 && $qdata['thread'] !== -1) { 
                            $pred = $qdata;
                        } elseif ($pred !== null) {
                            $pred['next'] = $s['thread'];
                            self::$waiters->set((string) $pred['thread'], $pred);
                            if ($pred['thread'] === 0 || $pred['thread'] === -1) {// check for race
                                continue 2;
                            }
                        } elseif (self::$stack->get($this->xid, 'stack') !== $q) {
                            continue 2;
                        } else {
                            self::$stack->set($this->xid, ['xid' => $this->xid, 'stack' => $s['thread']]);
                        }
                    }
                }
                break;
            }
        }
    }

    /**
     * Returns a string representation of this FutureTask.
     *
     * @implSpec
     * The default implementation returns a string identifying this
     * FutureTask, as well as its completion state.  The state, in
     * brackets, contains one of the strings {@code "Completed Normally"},
     * {@code "Completed Exceptionally"}, {@code "Cancelled"}, or {@code
     * "Not completed"}.
     *
     * @return a string representation of this FutureTask
     */
    public function __toString(): string
    {
        $status = "";
        switch (self::$state->get($this->xid, 'state')) {
            case self::NORMAL:
                $status = "[Completed normally]";
                break;
            case self::EXCEPTIONAL:
                $status = "[Completed exceptionally: " . $this->outcome->getMessage() . "]";
                break;
            case self::CANCELLED:
            case self::INTERRUPTING:
            case self::INTERRUPTED:
                $status = "[Cancelled]";
                break;
            default:
                $status = ($this->callable === null)
                    ? "[Not completed]"
                    : "[Not completed]";
        }
        return $status;
    }
}
