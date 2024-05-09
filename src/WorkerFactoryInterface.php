<?php

namespace Concurrent;

interface WorkerFactoryInterface
{
    public function newWorker(?RunnableInterface $task = null, ?ExecutorServiceInterface $executor = null): ThreadInterface;
}
