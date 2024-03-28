<?php

namespace Concurrent\Worker;

use Concurrent\Executor\ForkJoinPool;
use Concurrent\Queue\ForkJoinWorkQueue;
use Concurrent\Task\ForkJoinTask;
use Concurrent\ThreadInterface;

class ForkJoinWorker implements ThreadInterface
{
    /*
     * ForkJoinWorkers are managed by ForkJoinPools and perform
     * ForkJoinTasks.
     *
     * This class just maintains links to its pool and WorkQueue.
     */
    public ForkJoinPool $pool; // the pool this process works in
    public ForkJoinWorkQueue $workQueue; // work-stealing mechanics
    public $thread;
    public $pid;
    private bool $started = false;
    private $name;

    /**
     * Creates a ForkJoinWorker operating in the given pool.
     *
     * @param pool the pool this process works in
     */
    public function __construct(ForkJoinPool $pool)
    {
        $this->pool = $pool;        
        $this->pid = new \Swoole\Atomic\Long(0);
        $scope = $this;
        $args = $pool->getScopeArguments();
        $this->thread = new InterruptibleProcess(function ($process) use ($scope, $args) {
            $scope->pid->set($process->pid);
            ForkJoinTask::$fork->set((string) $process->pid, ['pid' => $process->pid]);
            $scope->run($process, ...$args);
        }, false);        
        $this->thread->useQueue(1, 2);
        $this->workQueue = $pool->registerWorker($this);
    }

    public function getPid(): ?int
    {
        return $this->pid->get();
    }

    public function interrupt(): void
    {
        $this->thread->interrupt();
    }

    public function isInterrupted(): bool
    {
        return $this->thread->isInterrupted();
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
    public function getPool(): ForkJoinPool
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
                $this->pool->runWorker($this->workQueue, $process, ...$args);
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

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
