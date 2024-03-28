<?php

namespace Concurrent\Task;

use Concurrent\{
    RunnableInterface,
    ThreadInterface
};

abstract class Completion extends ForkJoinTask implements RunnableInterface, AsynchronousCompletionTaskInterface
{
    public function __construct(...$args)
    {
        parent::__construct(...$args);
    }

    /**
     * Performs completion action if triggered, returning a
     * dependent that may need propagation, if one exists.
     *
     * @param mode SYNC, ASYNC, or NESTED
     */
    abstract public function tryFire(int $mode): ?CompletableFuture;

    /** Returns true if possibly still triggerable. Used by cleanStack. */
    abstract public function isLive(): bool;

    public $thread;

    

    public function run(ThreadInterface $process = null, ...$args): void
    {
        $this->thread = $process;
        $this->tryFire(CompletableFuture::ASYNC);
    }

    public function exec(?ThreadInterface $process, ...$args): bool
    {
        $this->thread = $process;
        $this->tryFire(CompletableFuture::ASYNC);
        return false;
    }

    public function getRawResult()
    {
        return null;
    }

    public function setRawResult($v): void
    {
    }
}
