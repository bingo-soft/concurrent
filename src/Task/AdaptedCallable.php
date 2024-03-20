<?php

namespace Concurrent\Task;

use Concurrent\{
    FutureInterface,
    RunnableInterface,
    ThreadInterface
};

class AdaptedCallable extends ForkJoinTask implements RunnableInterface, FutureInterface
{
    public $callable;

    public function __construct(callable $callable) {
        parent::__construct();
        $this->callable= $callable;
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
        $callable = $this->callable;
        self::$result->set($this->getXid(), ['result' => $callable()]);
        return true;
    }

    public function run(ThreadInterface $worker = null, ...$args): void
    {
        $this->invoke($worker, ...$args);
    }
}
