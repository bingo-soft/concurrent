<?php

namespace Concurrent;

interface RunnableScheduledFutureInterface extends RunnableFutureInterface, ScheduledFutureInterface
{
    /**
     * Returns {@code true} if this task is periodic. A periodic task may
     * re-run according to some schedule. A non-periodic task can be
     * run only once.
     *
     * @return {@code true} if this task is periodic
     */
    public function isPeriodic(): bool;
}
