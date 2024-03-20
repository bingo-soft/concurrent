<?php

namespace Concurrent\Task;

use Concurrent\{
    FutureInterface,
    RunnableInterface
};

class RunnableExecuteAction extends ForkJoinTask
{
    public $runnable;

    public function __construct(RunnableInterface $runnable) {
        parent::__construct();
        $this->runnable = $runnable;        
    }

    public function getRawResult() {
        return null;
    }

    public function setRawResult($v): void
    {
    }

    public function exec(?ThreadInterface $thread, ...$args): bool
    {
        $this->runnable->run($worker, ...$args);
        return true;
    }

    public function run(ThreadInterface $worker = null, ...$args): void
    {
        $this->invoke($worker, ...$args);
    }

    /*void internalPropagateException(Throwable ex) {
        rethrow(ex); // rethrow outside exec() catches.
    }*/
}
