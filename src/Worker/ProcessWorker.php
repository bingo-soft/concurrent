<?php

namespace Concurrent\Worker;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableInterface,
    ThreadInterface
};
use Concurrent\Lock\AbstractQueuedSynchronizer;

class ProcessWorker extends AbstractQueuedSynchronizer implements RunnableInterface, ThreadInterface
{
    public $firstTask;

    public $executor;

    public $thread;

    /**
     * Creates with given first task.
     * @param firstTask the first task (null if none)
     */
    public function __construct(?RunnableInterface $firstTask, ExecutorServiceInterface $executor)
    {
        //parent::__construct(SWOOLE_MUTEX);
        parent::__construct();
        $this->firstTask = $firstTask;
        $this->executor = $executor;
        $scope = $this;
        $args = $executor->getScopeArguments();
        $this->thread = new InterruptibleProcess(function ($process) use ($scope, $args) {
            $scope->run($process, ...$args);
        }, false);
        $this->thread->enableSharedInterrupt();
        if (!(method_exists($executor, 'getQueue')
            && $executor->getQueue() instanceof \Concurrent\Queue\ArrayBlockingQueue
            && $executor->getQueue()->isProcessShared())) {
            // Non-shared queue implementations still rely on the worker IPC.
            $this->thread->useQueue(0, 2);
        } else {
            $this->thread->disableQueueCleanup();
        }
    }

    public function start(): void
    {
        $this->thread->start();
    }

    public function interrupt(): void
    {
        $this->thread->interrupt();
    }

    /** Delegates main run loop to outer runWorker  */
    public function run(ThreadInterface $process = null, ...$args): void
    {
        try {
            $this->executor->runWorker($this, ...$args);
        } catch (\Throwable $throwable) {
            if (method_exists($this->executor, 'failPool')) {
                $this->executor->failPool();
            }
            throw $throwable;
        } finally {
            $this->thread->cleanup();
        }
    }

    // Lock methods
    //
    // The value 0 represents the unlocked state.
    // The value 1 represents the locked state.

    public function isHeldExclusively(?ThreadInterface $thread = null): bool
    {
        return $this->getState() !== 0;
    }

    public function tryAcquire(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        if ($this->compareAndSetState(0, 1)) {
            $this->setExclusiveOwnerThread(getmypid());
            return true;
        }
        return false;
    }

    public function tryRelease(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        $this->setExclusiveOwnerThread(-1);
        $this->setState(0);
        return true;
    }

    public function lock(?ThreadInterface $thread = null): void
    {
        $node = null;
        $this->acquire($thread, $node, 1);
    }

    public function tryLock(): bool
    {
        return $this->tryAcquire(null, 1);
    }

    public function unlock(?ThreadInterface $thread = null): void
    {
        $this->release($thread, 1);
    }

    public function isLocked(): bool
    {
        return $this->isHeldExclusively(null);
    }
}
