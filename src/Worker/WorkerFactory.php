<?php

namespace Concurrent\Worker;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableInterface
};

class WorkerFactory
{
    public static function create(string $workerType, ?RunnableInterface $firstTask, ExecutorServiceInterface $executor): RunnableInterface
    {
        switch ($workerType)
        {
            case 'process':
                return new ProcessWorker($firstTask, $executor);
            default:
                throw new \Exception(sprintf("Unknown worker type '%s'", $workerType));
        }
    }
}
