<?php

namespace Concurrent\Executor;

use Concurrent\{
    FutureInterface,
    RunnableInterface,
    RunnableScheduledFutureInterface,
    ScheduledExecutorServiceInterface,
    ScheduledFutureInterface,
    TimeUnit
};
use Concurrent\Task\ScheduledFutureTask;
use Concurrent\Queue\DelayedWorkQueue;

class ScheduledPoolExecutor extends DefaultPoolExecutor implements ScheduledExecutorServiceInterface
{
    /*
     * This class specializes DefaultPoolExecutor implementation by
     *
     * 1. Using a custom task type ScheduledFutureTask, even for tasks
     *    that don't require scheduling because they are submitted
     *    using ExecutorService rather than ScheduledExecutorService
     *    methods, which are treated as tasks with a delay of zero.
     *
     * 2. Using a custom queue (DelayedWorkQueue), a variant of
     *    unbounded DelayQueue. The lack of capacity constraint and
     *    the fact that corePoolSize and maximumPoolSize are
     *    effectively identical simplifies some execution mechanics
     *    (see delayedExecute) compared to DefaultPoolExecutor.
     *
     * 3. Supporting optional run-after-shutdown parameters, which
     *    leads to overrides of shutdown methods to remove and cancel
     *    tasks that should NOT be run after shutdown, as well as
     *    different recheck logic when task (re)submission overlaps
     *    with a shutdown.
     *
     * 4. Task decoration methods to allow interception and
     *    instrumentation, which are needed because subclasses cannot
     *    otherwise override submit methods to get this effect. These
     *    don't have any impact on pool control logic though.
     */

    /**
     * False if should cancel/suppress periodic tasks on shutdown.
     */
    private $continueExistingPeriodicTasksAfterShutdown = false;

    /**
     * False if should cancel non-periodic not-yet-expired tasks on shutdown.
     */
    private $executeExistingDelayedTasksAfterShutdown = true;

    /**
     * True if ScheduledFutureTask.cancel should remove from queue.
     */
    public $removeOnCancel = false;

    /**
     * Sequence number to break scheduling ties, and in turn to
     * guarantee FIFO order among tied entries.
     */
    private static $sequencer;// = new AtomicLong();

     /**
     * Returns true if can run a task given current run state and
     * run-after-shutdown parameters.
     */
    public function canRunInCurrentRunState(RunnableScheduledFutureInterface $task): bool
    {
        if (!$this->isShutdown()) {
            return true;
        }
        /*if ($this->isStopped()) {
            return false;
        }*/
        return $task->isPeriodic()
            ? $this->continueExistingPeriodicTasksAfterShutdown
            : ($this->executeExistingDelayedTasksAfterShutdown
               || $task->getDelay() <= 0);
    }

    /**
     * Main execution method for delayed or periodic tasks.  If pool
     * is shut down, rejects the task. Otherwise adds task to queue
     * and starts a thread, if necessary, to run it.  (We cannot
     * prestart the thread to run the task because the task (probably)
     * shouldn't be run yet.)  If the pool is shut down while the task
     * is being added, cancel and remove it if required by state and
     * run-after-shutdown parameters.
     *
     * @param task the task
     */
    private function delayedExecute(RunnableScheduledFutureInterface $task): void
    {
        if ($this->isShutdown()) {
            $this->reject($task);
        } else {
            parent::getQueue()->add($task);
            if (!$this->canRunInCurrentRunState($task) && $this->remove($task)) {
                $task->cancel(false, $this);
            } else {
                $this->ensurePrestart();
            }
        }
    }

    /**
     * Requeues a periodic task unless current run state precludes it.
     * Same idea as delayedExecute except drops task rather than rejecting.
     *
     * @param task the task
     */
    public function reExecutePeriodic(RunnableScheduledFutureInterface | string $task): void
    {
        if (is_string($task)) {
            $task = unserialize($task);
        }
        if ($this->canRunInCurrentRunState($task)) {
            $queue = parent::getQueue();
            $queue->add($task);
            if ($this->canRunInCurrentRunState($task) || !$this->remove($task)) {
                $this->ensurePrestart();
                return;
            }
        }
        $task->cancel(false, $this);
    }

    /**
     * Cancels and clears the queue of all tasks that should not be run
     * due to shutdown policy.  Invoked within super.shutdown.
     */
    public function onShutdown(): void
    {
        $q = parent::getQueue();
        $keepDelayed =
            $this->getExecuteExistingDelayedTasksAfterShutdownPolicy();
        $keepPeriodic =
            $this->getContinueExistingPeriodicTasksAfterShutdownPolicy();
        // Traverse snapshot to avoid iterator exceptions
        // TODO: implement and use efficient removeIf
        // super.getQueue().removeIf(...);
        foreach ($q->toArray() as $e) {
            $eu = unserialize($e['task']);
            if ($eu instanceof RunnableScheduledFutureInterface) {
                if (($eu->isPeriodic()
                     ? !$keepPeriodic
                     : (!$keepDelayed && $eu->getDelay() > 0))
                    || $eu->isCancelled()) { // also remove if already cancelled
                    if ($q->remove($eu)) {
                        $eu->cancel(false, $this);
                    }
                }
            }
        }
        $this->tryTerminate();
    }

    /**
     * Modifies or replaces the task used to execute a callable.
     * This method can be used to override the concrete
     * class used for managing internal tasks.
     * The default implementation simply returns the given task.
     *
     * @param callable the submitted Callable
     * @param task the task created to execute the callable
     * @param <V> the type of the task's result
     * @return a task that can execute the callable
     * @since 1.6
     */
    public function decorateTask(
        RunnableInterface | callable $callable, RunnableScheduledFutureInterface $task): RunnableScheduledFutureInterface
        {
        return $task;
    }

    /**
     * The default keep-alive time for pool threads.
     *
     * Normally, this value is unused because all pool threads will be
     * core threads, but if a user creates a pool with a corePoolSize
     * of zero (against our advice), we keep a thread alive as long as
     * there are queued tasks.  If the keep alive time is zero (the
     * historic value), we end up hot-spinning in getTask, wasting a
     * CPU.  But on the other hand, if we set the value too high, and
     * users create a one-shot pool which they don't cleanly shutdown,
     * the pool's non-daemon threads will prevent JVM termination.  A
     * small but non-zero value (relative to a JVM's lifetime) seems
     * best.
     */
    private const DEFAULT_KEEPALIVE_MILLIS = 10;

    //8KB per serialized task, should be enough
    public const DEFAULT_SIZE = 8192;

    //Maximum number of dependent tasks
    public const DEFAULT_MAX_DEPENDENT_TASKS = 64;

    /**
     * Creates a new {@code ScheduledThreadPoolExecutor} with the
     * given initial parameters.
     *
     * @param corePoolSize the number of threads to keep in the pool, even
     *        if they are idle, unless {@code allowCoreThreadTimeOut} is set
     */
    public function __construct(
        ?int $corePoolSize = null,
        ?int $maximumPoolSize = null,
        int $keepAliveTime = 0,
        string $unit = TimeUnit::MILLISECONDS,
        string $workerType = 'process',
        ?int $maxTasks = self::DEFAULT_MAX_DEPENDENT_TASKS,
        ?int $maxTaskSize = self::DEFAULT_SIZE,
        ?int $notificationPort = 1081
    ) {
        parent::__construct($poolSize, $maximumPoolSize, self::DEFAULT_KEEPALIVE_MILLIS, $unit, new DelayedWorkQueue($notificationPort), $workerType);
        $this->sequencer = new \Swoole\Atomic\Long(0);
    }

    /**
     * Returns the nanoTime-based trigger time of a delayed action.
     */
    public function triggerTime(int $delay, ?string $unit = null): int
    {
        if ($unit !== null) {
            $delay = TimeUnit::toNanos($delay < 0 ? 0 : $delay, $unit);
        }
        return hrtime(true) +
            (($delay < (PHP_INT_MAX >> 1)) ? $delay : $this->overflowFree($delay));
    }

    /**
     * Constrains the values of all delays in the queue to be within
     * Long.MAX_VALUE of each other, to avoid overflow in compareTo.
     * This may occur if a task is eligible to be dequeued, but has
     * not yet been, while some other task is added with a delay of
     * Long.MAX_VALUE.
     */
    private function overflowFree(int $delay): int
    {
        $head = parent::getQueue()->peek();
        if ($head !== null) {
            $headDelay = $head->getDelay();
            if ($headDelay < 0 && ($delay - $headDelay < 0)) {
                $delay = PHP_INT_MAX + $headDelay;
            }
        }
        return $delay;
    }

    /**
     * @throws RejectedExecutionException {@inheritDoc}
     * @throws NullPointerException       {@inheritDoc}
     */
    public function schedule(RunnableInterface | callable $callable, int $delay, string $unit): ?ScheduledFutureInterface
    {
        $s = $this->sequencer->get();
        $this->sequencer->add(1);
        $t = $this->decorateTask($callable, new ScheduledFutureTask($callable, $this->triggerTime($delay, $unit), null, $s));
        $this->delayedExecute($t);
        return $t;
    }

    public function scheduleAtFixedRate(RunnableInterface | callable $command, int $initialDelay, int $period, string $unit): ?ScheduledFutureInterface
    {
        if ($period <= 0) {
            throw new \Exception("IllegalArgument");
        }
        $s = $this->sequencer->get();
        $this->sequencer->add(1);
        $sft = new ScheduledFutureTask(
            $command,
            $this->triggerTime($initialDelay, $unit),
            TimeUnit::toNanos($period, $unit),
            $s
        );
        $t = $this->decorateTask($command, $sft);
        $sft->outerTask = $t;
        $this->delayedExecute($t);
        return $t;
    }

    /**
     * Submits a periodic action that becomes enabled first after the
     * given initial delay, and subsequently with the given delay
     * between the termination of one execution and the commencement of
     * the next.
     *
     * <p>The sequence of task executions continues indefinitely until
     * one of the following exceptional completions occur:
     * <ul>
     * <li>The task is {@linkplain Future#cancel explicitly cancelled}
     * via the returned future.
     * <li>Method {@link #shutdown} is called and the {@linkplain
     * #getContinueExistingPeriodicTasksAfterShutdownPolicy policy on
     * whether to continue after shutdown} is not set true, or method
     * {@link #shutdownNow} is called; also resulting in task
     * cancellation.
     * <li>An execution of the task throws an exception.  In this case
     * calling {@link Future#get() get} on the returned future will throw
     * {@link ExecutionException}, holding the exception as its cause.
     * </ul>
     * Subsequent executions are suppressed.  Subsequent calls to
     * {@link Future#isDone isDone()} on the returned future will
     * return {@code true}.
     *
     * @throws RejectedExecutionException {@inheritDoc}
     * @throws NullPointerException       {@inheritDoc}
     * @throws IllegalArgumentException   {@inheritDoc}
     */
    public function scheduleWithFixedDelay(
        RunnableInterface | callable $command,
        int $initialDelay,
        int $delay,
        string $unit
    ): ?ScheduledFutureInterface {
        if ($delay <= 0) {
            throw new \Exception("IllegalArgument");
        }
        $s = $this->sequencer->get();
        $this->sequencer->add(1);
        $sft = new ScheduledFutureTask(
            $command,
            $this->triggerTime($initialDelay, $unit),
            TimeUnit::toNanos($delay, $unit),
            $s
        );
        $t = $this->decorateTask($command, $sft);
        $sft->outerTask = $t;
        $this->delayedExecute($t);
        return $t;
    }

    /**
     * Executes {@code command} with zero required delay.
     * This has effect equivalent to
     * {@link #schedule(Runnable,long,TimeUnit) schedule(command, 0, anyUnit)}.
     * Note that inspections of the queue and of the list returned by
     * {@code shutdownNow} will access the zero-delayed
     * {@link ScheduledFuture}, not the {@code command} itself.
     *
     * <p>A consequence of the use of {@code ScheduledFuture} objects is
     * that {@link ThreadPoolExecutor#afterExecute afterExecute} is always
     * called with a null second {@code Throwable} argument, even if the
     * {@code command} terminated abruptly.  Instead, the {@code Throwable}
     * thrown by such a task can be obtained via {@link Future#get}.
     *
     * @throws RejectedExecutionException at discretion of
     *         {@code RejectedExecutionHandler}, if the task
     *         cannot be accepted for execution because the
     *         executor has been shut down
     * @throws NullPointerException {@inheritDoc}
     */
    public function execute(RunnableInterface | callable $command): void
    {
        $this->schedule($command, 0, TimeUnit::NANOSECONDS);
    }

    // Override AbstractExecutorService methods

    /**
     * @throws RejectedExecutionException {@inheritDoc}
     * @throws NullPointerException       {@inheritDoc}
     */
    public function submit(RunnableInterface | callable $task): FutureInterface
    {
        return $this->schedule($task, 0, TimeUnit::NANOSECONDS);
    }

    
    /**
     * Sets the policy on whether to continue executing existing
     * periodic tasks even when this executor has been {@code shutdown}.
     * In this case, executions will continue until {@code shutdownNow}
     * or the policy is set to {@code false} when already shutdown.
     * This value is by default {@code false}.
     *
     * @param value if {@code true}, continue after shutdown, else don't
     * @see #getContinueExistingPeriodicTasksAfterShutdownPolicy
     */
    public function setContinueExistingPeriodicTasksAfterShutdownPolicy(bool $value): void
    {
        $this->continueExistingPeriodicTasksAfterShutdown = $value;
        if (!$value && $this->isShutdown()) {
            $this->onShutdown();
        }
    }

    /**
     * Gets the policy on whether to continue executing existing
     * periodic tasks even when this executor has been {@code shutdown}.
     * In this case, executions will continue until {@code shutdownNow}
     * or the policy is set to {@code false} when already shutdown.
     * This value is by default {@code false}.
     *
     * @return {@code true} if will continue after shutdown
     * @see #setContinueExistingPeriodicTasksAfterShutdownPolicy
     */
    public function getContinueExistingPeriodicTasksAfterShutdownPolicy(): bool
    {
        return $this->continueExistingPeriodicTasksAfterShutdown;
    }

    /**
     * Sets the policy on whether to execute existing delayed
     * tasks even when this executor has been {@code shutdown}.
     * In this case, these tasks will only terminate upon
     * {@code shutdownNow}, or after setting the policy to
     * {@code false} when already shutdown.
     * This value is by default {@code true}.
     *
     * @param value if {@code true}, execute after shutdown, else don't
     * @see #getExecuteExistingDelayedTasksAfterShutdownPolicy
     */
    public function setExecuteExistingDelayedTasksAfterShutdownPolicy(bool $value): void
    {
        $this->executeExistingDelayedTasksAfterShutdown = $value;
        if (!$value && $this->isShutdown()) {
            $this->onShutdown();
        }
    }

    /**
     * Gets the policy on whether to execute existing delayed
     * tasks even when this executor has been {@code shutdown}.
     * In this case, these tasks will only terminate upon
     * {@code shutdownNow}, or after setting the policy to
     * {@code false} when already shutdown.
     * This value is by default {@code true}.
     *
     * @return {@code true} if will execute after shutdown
     * @see #setExecuteExistingDelayedTasksAfterShutdownPolicy
     */
    public function getExecuteExistingDelayedTasksAfterShutdownPolicy(): bool
    {
        return $this->executeExistingDelayedTasksAfterShutdown;
    }

    /**
     * Sets the policy on whether cancelled tasks should be immediately
     * removed from the work queue at time of cancellation.  This value is
     * by default {@code false}.
     *
     * @param value if {@code true}, remove on cancellation, else don't
     * @see #getRemoveOnCancelPolicy
     * @since 1.7
     */
    public function setRemoveOnCancelPolicy(bool $value): void
    {
        $this->removeOnCancel = $value;
    }

    /**
     * Gets the policy on whether cancelled tasks should be immediately
     * removed from the work queue at time of cancellation.  This value is
     * by default {@code false}.
     *
     * @return {@code true} if cancelled tasks are immediately removed
     *         from the queue
     * @see #setRemoveOnCancelPolicy
     * @since 1.7
     */
    public function getRemoveOnCancelPolicy(): bool
    {
        return $this->removeOnCancel;
    }
}
