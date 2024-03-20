<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

abstract class ReentrantLockSync extends AbstractQueuedSynchronizer
{
    /**
     * Performs {@link Lock#lock}. The main reason for subclassing
     * is to allow fast path for nonfair version.
     */
    abstract public function lock(?ThreadInterface $thread = null): void;

    /**
     * Performs non-fair tryLock.  tryAcquire is implemented in
     * subclasses, but both need nonfair try for trylock method.
     */
    public function nonfairTryAcquire(?ThreadInterface $current = null, int $acquires = 0): bool
    {
        $c = $this->getState();
        if ($c == 0) {
            if ($this->compareAndSetState(0, $acquires)) {
                $this->setExclusiveOwnerThread($current);
                return true;
            }
        } elseif ($current == $this->getExclusiveOwnerThread()) {
            $nextc = $c + $acquires;
            if ($nextc < 0) {// overflow
                throw new \Exception("Maximum lock count exceeded");
            }
            $this->setState($nextc);
            return true;
        }
        return false;
    }

    public function tryRelease(?ThreadInterface $current = null, int $releases = 0): bool
    {
        $c = $this->getState() - $releases;
        if ($current != $this->getExclusiveOwnerThread()) {
            throw new \Exception("Illegal monitor state");
        }
        $free = false;
        if ($c == 0) {
            $free = true;
            $this->setExclusiveOwnerThread(null);
        }
        $this->setState($c);
        return $free;
    }

    public function isHeldExclusively(?ThreadInterface $thread = null): bool
    {
        // While we must in general read state before owner,
        // we don't need to do so to check if current thread is owner
        return $this->getExclusiveOwnerThread() == $thread;
    }

    public function newCondition(): ConditionObject
    {
        return new ConditionObject($this);
    }

    // Methods relayed from outer class

    public function getOwner(): ?ThreadInterface
    {
        return $this->getState() === 0 ? null : $this->getExclusiveOwnerThread();
    }

    public function getHoldCount(?ThreadInterface $thread = null): int
    {
        return $this->isHeldExclusively($thread) ? $this->getState() : 0;
    }

    public function isLocked(): bool
    {
        return $this->getState() !== 0;
    }
}
