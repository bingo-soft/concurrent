<?php

namespace Concurrent\Task;

class WaitNode
{
    public $thread;
    public $next;
    public function __construct() {
        $this->thread = new \Swoole\Atomic\Long(getmypid());
    }
}
