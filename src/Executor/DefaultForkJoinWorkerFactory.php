<?php

namespace Concurrent\Executor;

use Concurrent\Worker\ForkJoinWorker;

class DefaultForkJoinWorkerFactory implements ForkJoinWorkerFactoryInterface
{
    public function newThread(ForkJoinPool $pool): ForkJoinWorker
    {
        return new ForkJoinWorker($pool);
    }
}
