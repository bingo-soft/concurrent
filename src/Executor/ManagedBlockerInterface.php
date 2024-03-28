<?php

namespace Concurrent\Executor;

interface ManagedBlockerInterface
{
    /**
     * Possibly blocks the current thread, for example waiting for
     * a lock or condition.
     *
     * @return {@code true} if no additional blocking is necessary
     * (i.e., if isReleasable would return true)
     */
    public function block(): bool;

    /**
     * Returns {@code true} if blocking is unnecessary.
     * @return {@code true} if blocking is unnecessary
     */
    public function isReleasable(): bool;
}
