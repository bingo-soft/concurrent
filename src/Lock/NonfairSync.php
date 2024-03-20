<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class NonfairSync extends ReentrantLockSync
{
    /**
     * Performs lock.  Try immediate barge, backing up to normal
     * acquire on failure.
     */
    public function lock(?ThreadInterface $thread = null): void
    {
        if ($this->compareAndSetState(0, 1)) {
            $this->setExclusiveOwnerThread($thread);
        } else {
            $this->acquire($thread, 1);
        }
    }

    public function acquire(?ThreadInterface $thread = null, int $acquires = 0)
    {
        return $this->nonfairTryAcquire($thread, $acquires);
    }
}
