<?php

namespace Concurrent;

interface ExecutorServiceInterface extends ExecutorInterface
{
    public function shutdown(): void;

    public function isShutdown(): bool;

    public function isTerminated(): bool;

    public function awaitTermination(int $timeout, string $unit);
}
