<?php

namespace Concurrent\Executor;

use Concurrent\Lock\LockSupport;

trait NotificationTrait
{
    public function listen(int $port): void
    {
        LockSupport::init($port);
    }
}