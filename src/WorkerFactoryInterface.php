<?php

namespace Concurrent;

interface WorkerFactoryInterface
{
    public function newWorker(?ExecutorServiceInterface $executor = null): ThreadInterface;
}
