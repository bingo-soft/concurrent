<?php

namespace Concurrent\Worker;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableInterface,
    ThreadInterface
};

class ProcessWorker extends \Swoole\Lock implements RunnableInterface
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
        parent::__construct(SWOOLE_MUTEX);
        $this->firstTask = $firstTask;
        $this->executor = $executor;
        $scope = $this;
        $args = $executor->getScopeArguments();
        $this->thread = new InterruptibleProcess(function ($process) use ($scope, $args) {
            $scope->run($process, ...$args);
        }, false);
        $this->thread->useQueue(1, 2);
    }

    public function start(): void
    {
        $this->thread->start();
    }

    /** Delegates main run loop to outer runWorker  */
    public function run(ThreadInterface $process, ...$args): void
    {
        $this->executor->runWorker($this, $process, ...$args);
    }
}
