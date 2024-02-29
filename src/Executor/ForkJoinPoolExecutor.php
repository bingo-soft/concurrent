<?php

namespace Concurrent\Executor;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableInterface,
    ThreadInterface
};
use Concurrent\TimeUnit;
use Concurrent\Queue\{
    ArrayBlockingQueue,
    BlockingQueueInterface
};
use Concurrent\Worker\WorkerFactory;

class ForkJoinPoolExecutor implements ExecutorServiceInterface
{
    use NotificationTrait;
    
    public const SCANNING     = 1;
    public const FIFO_QUEUE   = 1 << 16;
    
    public function shutdown(): void
    {

    }

    public function isShutdown(): bool
    {
        return false;
    }

    public function isTerminated(): bool
    {
        return false;
    }

    public function awaitTermination(int $timeout, string $unit)
    {

    }

    public function execute(RunnableInterface $command): void
    {

    }    
}
