<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class FairSync extends ReentrantLockSync
{
    public function lock(?ThreadInterface $thread = null): void
    {
        $this->acquire($thread, 1);
    }

    /**
     * Fair version of tryAcquire.  Don't grant access unless
     * recursive call or no waiters or is first.
     */
    public function tryAcquire(?ThreadInterface $current = null, int $arg = 0): bool
    {
        $c = $this->getState();        
        if ($c == 0) {
            if (!$this->hasQueuedPredecessors($current) && $this->compareAndSetState(0, $arg)) {
                $this->setExclusiveOwnerThread($current);
                return true;
            }
        } elseif ($current == $this->getExclusiveOwnerThread()) {
            $nextc = $c + $arg;
            if ($nextc < 0) {
                throw new \Exception("Maximum lock count exceeded");
            }
            $this->setState($nextc);
            return true;
        }
        return false;
    }
}
