<?php

namespace Concurrent\Executor;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableInterface,
    ThreadInterface,
    WorkerFactoryInterface
};
use Concurrent\Worker\ForkJoinWorker;

class DefaultForkJoinWorkerFactory implements WorkerFactoryInterface
{
    public function newWorker(?RunnableInterface $task = null, ?ExecutorServiceInterface $executor = null): ThreadInterface
    {
        return new ForkJoinWorker($executor);
    }
}
