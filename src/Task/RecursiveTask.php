<?php

namespace Concurrent\Task;

use Concurrent\ThreadInterface;
use Concurrent\Lock\NotificationInterface;

abstract class RecursiveTask extends ForkJoinTask
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The main computation performed by this task.
     * @return the result of the computation
     */
    abstract public function compute(ThreadInterface $thread, ...$args);

    public function castResult($result)
    {
        return $result;
    }

    public function getRawResult()
    {
        return $this->castResult(self::$result->get($this->getXid(), 'result'));
    }

    public function setRawResult($value): void
    {
        self::$result->set($this->getXid(), ['result' => $value]);
    }

    /**
     * Implements execution conventions for RecursiveTask.
     */
    public function exec(?ThreadInterface $worker, ...$args): bool
    {
        self::$result->set($this->getXid(), ['result' => $this->compute($worker, ...$args)]);
        return true;
    }
}
