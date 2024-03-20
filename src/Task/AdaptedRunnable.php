<?php

namespace Concurrent\Task;

use Concurrent\{
    FutureInterface,
    RunnableInterface,
    ThreadInterface
};

class AdaptedRunnable extends ForkJoinTask implements RunnableInterface, FutureInterface
{
    public $runnable;

    public function __construct(RunnableInterface $runnable, &$result) {
        parent::__construct();
        $this->runnable = $runnable;
        self::$result->set($this->getXid(), ['result' => $result]);// OK to set this even before completion
    }

    public function getRawResult() {
        return self::$result->get($this->getXid(), 'result');
    }

    public function setRawResult($v): void
    {
        self::$result->set($this->getXid(), ['result' => $v]);
    }

    public function exec(?ThreadInterface $worker, ...$args): bool
    {
        $this->runnable->run($worker, ...$args);
        return true;
    }

    public function run(ThreadInterface $worker = null, ...$args): void
    {
        $this->invoke($worker, ...$args);
    }
}
