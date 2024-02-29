<?php

namespace Concurrent\Worker;

use Concurrent\Executor\ForkJoinPoolExecutor;
use Concurrent\Queue\ForkJoinWorkQueue;
use Concurrent\ThreadInterface;

class ForkJoinWorkerProcess implements ThreadInterface
{
    /*
     * ForkJoinWorkerProcesss are managed by ForkJoinPoolExecutors and perform
     * ForkJoinTasks.
     *
     * This class just maintains links to its pool and WorkQueue.
     */
    public ForkJoinPoolExecutor $pool; // the pool this process works in
    public ForkJoinWorkQueue $workQueue; // work-stealing mechanics
    public $thread;
    private bool $started = false;

    /**
     * Creates a ForkJoinWorkerProcess operating in the given pool.
     *
     * @param pool the pool this process works in
     */
    public function __construct(ForkJoinPoolExecutor $pool)
    {
        $this->pool = $pool;
        $this->workQueue = $pool->registerWorker($this);
        
        $scope = $this;
        $args = $pool->getScopeArguments();
        $this->thread = new InterruptibleProcess(function ($process) use ($scope, $args) {
            $scope->run($process, ...$args);
        }, false);
        $this->thread->useQueue(1, 2);
    }

    public function start(): void
    {
        $this->started = true;
        $this->thread->start();
    }

    /**
     * Returns the pool hosting this process.
     *
     * @return the pool
     */
    public function getPool(): ForkJoinPoolExecutor
    {
        return $this->pool;
    }

    /**
     * Returns the unique index number of this process in its pool.
     * The returned value ranges from zero to the maximum number of
     * processes (minus one) that may exist in the pool, and does not
     * change during the lifetime of the process. This method may be
     * useful for applications that track status or collect results
     * per-worker-process rather than per-task.
     *
     * @return the index number
     */
    public function getPoolIndex(): int
    {
        return $this->workQueue->getPoolIndex();
    }

    /**
     * Initializes internal state after construction but before
     * processing any tasks. If you override this method, you must
     * invoke {@code super.onStart()} at the beginning of the method.
     * Initialization requires care: Most fields must have legal
     * default values, to ensure that attempted accesses from other
     * processes work correctly even before this process starts
     * processing tasks.
     */
    protected function onStart(): void
    {
    }

    /**
     * Performs cleanup associated with termination of this worker
     * process.  If you override this method, you must invoke
     * {@code super.onTermination} at the end of the overridden method.
     *
     * @param exception the exception causing this process to abort due
     * to an unrecoverable error, or {@code null} if completed normally
     */
    protected function onTermination(?\Throwable $exception = null): void
    {
    }

     /**
     * This method is required to be public, but should never be
     * called explicitly. It performs the main run loop to execute
     * {@link ForkJoinTask}s.
     */
    public function run(ThreadInterface $process = null, ...$args): void
    {
        if ($this->workQueue->isAllocated() === 0) { // only run once
            $exception = null;
            try {
                $this->onStart();
                $this->pool->runWorker($this, $process, ...$args);
            } catch (\Throwable $ex) {
                $exception = $ex;
            } finally {
                try {
                    $this->onTermination($exception);
                } catch (\Throwable $ex) {
                    if ($exception == null) {
                        $exception = $ex;
                    }
                } finally {
                    $this->pool->deregisterWorker($this, $exception);
                }
            }
        }
    }

    public function afterTopLevelExec(): void
    {
    }
}
