<?php

namespace Concurrent\Executor;

use Concurrent\{
    ExecutorServiceInterface,
    ThreadInterface,
    WorkerFactoryInterface
};
use Concurrent\Worker\ForkJoinWorker;

class DefaultForkJoinWorkerFactory implements WorkerFactoryInterface
{
    public function newWorker(?ExecutorServiceInterface $executor = null): ThreadInterface
    {
        return new ForkJoinWorker($executor);
    }
}
