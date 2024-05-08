<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

abstract class AbstractOwnableSynchronizer implements SynchronizerInterface
{
    public function __construct()
    {
        $this->exclusiveOwnerThread = new \Swoole\Atomic\Long(-1);
    }

    /**
     * The current owner of exclusive mode synchronization.
     */
    protected $exclusiveOwnerThread;

    /**
     * Sets the thread that currently owns exclusive access.
     * A {@code null} argument indicates that no thread owns access.
     * This method does not otherwise impose any synchronization or
     * {@code volatile} field accesses.
     * @param thread the owner thread
     */
    protected function setExclusiveOwnerThread(int $pid): void
    {
        $this->exclusiveOwnerThread->set($pid);
    }

    /**
     * Returns the thread last set by {@code setExclusiveOwnerThread},
     * or {@code null} if never set.  This method does not otherwise
     * impose any synchronization or {@code volatile} field accesses.
     * @return the owner thread
     */
    protected function getExclusiveOwnerThread(): int
    {
        return $this->exclusiveOwnerThread->get();
    }
}