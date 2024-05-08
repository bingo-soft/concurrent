<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class FairSync extends ReentrantLockSync
{
    /**
     * Acquires only if reentrant or queue is empty.
     */
    public function initialTryLock(?ThreadInterface $thread = null): bool
    {
        $current = getmypid();
        $c = $this->getState();
        if ($c === 0) {
            if (!$this->hasQueuedThreads() && $this->compareAndSetState(0, 1)) {
                $this->setExclusiveOwnerThread($current);
                return true;
            }
        } elseif ($this->getExclusiveOwnerThread() === $current) {
            if (++$c < 0) {// overflow
                throw new \Exception("Maximum lock count exceeded");
            }
            $this->setState($c);
            return true;
        }
        return false;
    }

    /**
     * Acquires only if thread is first waiter or empty
     */
    public function tryAcquire(?ThreadInterface $thread = null, int $acquires = 0): bool
    {
        if ($this->getState() === 0 && !$this->hasQueuedPredecessors() && $this->compareAndSetState(0, $acquires)) {
            $this->setExclusiveOwnerThread(getmypid());
            return true;
        }
        return false;
    }
}
