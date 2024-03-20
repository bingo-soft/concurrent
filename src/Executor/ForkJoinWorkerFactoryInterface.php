<?php

namespace Concurrent\Executor;

use Concurrent\Worker\ForkJoinWorker;

interface ForkJoinWorkerFactoryInterface
{
    public function newThread(ForkJoinPool $pool): ForkJoinWorker;
}
