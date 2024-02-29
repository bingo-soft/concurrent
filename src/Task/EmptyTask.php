<?php

namespace Concurrent\Task;

class EmptyTask extends ForkJoinTask
{
    public function __construct()
    {
        $this->status = new \Swoole\Atomic\Long(ForkJoinTask::NORMAL); 
    } // force done

    public function getRawResult() {
        return null;
    }

    public function setRawResult($x = null): void
    {
    }

    public function exec(): bool
    {
        return true;
    }
}
