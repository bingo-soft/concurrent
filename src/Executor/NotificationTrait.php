<?php

namespace Concurrent\Executor;

use Concurrent\Lock\LockSupport;

trait NotificationTrait
{
    public function listen(int $port = 1081): void
    {
        LockSupport::init($port);
    }
}