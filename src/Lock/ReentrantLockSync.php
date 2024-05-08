<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

abstract class ReentrantLockSync extends AbstractQueuedSynchronizer
{
    public function tryLock(): bool
    {
        $current = getmypid();
        $c = $this->getState();
        if ($c === 0) {
            if ($this->compareAndSetState(0, 1)) {
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

    abstract public function initialTryLock(?ThreadInterface $thread = null): bool;

    public function lock(?ThreadInterface $thread = null): void
    {
        if (!$this->initialTryLock($thread)) {
            $node = null;
            $this->acquire($thread, $node, 1);
        }
    }

    public function lockInterruptibly(?ThreadInterface $thread = null): void
    {
        if ($thread !== null && $thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        if (!$this->initialTryLock($thread)) {
            $node = null;
            $this->acquireInterruptibly($thread, $node, 1);
        }
    }

    public function tryLockNanos(?ThreadInterface $thread = null, int $nanos = 0): bool
    {
        if ($thread !== null && $thread->isInterrupted()) {
            throw new \Exception("Interrupted");
        }
        return $this->initialTryLock($thread) || $this->tryAcquireNanos($thread, 1, $nanos);
    }

    public function tryRelease(?ThreadInterface $thread = null, int $releases = 0): bool
    {
        $pid = $thread !== null ? $thread->pid : getmypid();
        $c = $this->getState() - $releases;
        if ($this->getExclusiveOwnerThread() !== getmypid()) {
            throw new \Exception("Illegal monitor state " . $this->getExclusiveOwnerThread() . " -> " . getmypid() . "\n");
        }
        $free = ($c === 0);
        if ($free) {
            $this->setExclusiveOwnerThread(-1);
        }
        $this->setState($c);
        return $free;
    }

    public function isHeldExclusively(?ThreadInterface $thread = null): bool
    {
        // While we must in general read state before owner,
        // we don't need to do so to check if current thread is owner
        return $this->getExclusiveOwnerThread() === getmypid();
    }

    public function newCondition(): ConditionObject
    {
        return new ConditionObject($this);
    }

    // Methods relayed from outer class

    public function getOwner(): ?int
    {
        return $this->getState() == 0 ? null : $this->getExclusiveOwnerThread();
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
