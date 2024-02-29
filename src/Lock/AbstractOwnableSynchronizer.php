<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

abstract class AbstractOwnableSynchronizer implements SynchronizerInterface
{
    /**
     * Empty constructor for use by subclasses.
     */
    public function __construct()
    {
    }

    /**
     * The current owner of exclusive mode synchronization.
     */
    private $exclusiveOwnerThread;

    /**
     * Sets the thread that currently owns exclusive access.
     * A {@code null} argument indicates that no thread owns access.
     * This method does not otherwise impose any synchronization or
     * {@code volatile} field accesses.
     * @param thread the owner thread
     */
    protected function setExclusiveOwnerThread(?ThreadInterface $thread): void
    {
        $this->exclusiveOwnerThread = $thread;
    }

    /**
     * Returns the thread last set by {@code setExclusiveOwnerThread},
     * or {@code null} if never set.  This method does not otherwise
     * impose any synchronization or {@code volatile} field accesses.
     * @return the owner thread
     */
    protected function getExclusiveOwnerThread(): ?ThreadInterface
    {
        return $this->exclusiveOwnerThread;
    }
}