<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class NonfairSync extends Sync
{
    /**
     * Performs lock.  Try immediate barge, backing up to normal
     * acquire on failure.
     */
    public function lock(ThreadInterface $thread): void
    {
        if ($this->compareAndSetState(0, 1)) {
            $this->setExclusiveOwnerThread($thread);
        } else {
            $this->acquire($thread, 1);
        }
    }

    public function acquire(ThreadInterface $thread, int $acquires)
    {
        return $this->nonfairTryAcquire($thread, $acquires);
    }
}
