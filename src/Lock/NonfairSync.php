<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class NonfairSync extends ReentrantLockSync
{
    public function initialTryLock(?ThreadInterface $thread = null): bool
    {
        $current = getmypid();
        if ($this->compareAndSetState(0, 1)) { // first attempt is unguarded
            $this->setExclusiveOwnerThread($current);
            return true;
        } elseif ($this->getExclusiveOwnerThread() === $current) {
            $c = $this->getState() + 1;
            if ($c < 0) {// overflow
                throw new Error("Maximum lock count exceeded");
            }
            $this->setState($c);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Acquire for non-reentrant cases after initialTryLock prescreen
     */
    public function tryAcquire(?ThreadInterface $thread = null, int $acquires = 0): bool
    {
        if ($this->getState() === 0 && $this->compareAndSetState(0, $acquires)) {
            $this->setExclusiveOwnerThread(getmypid());
            return true;
        }
        return false;
    }
}
