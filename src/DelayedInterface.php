<?php

namespace Concurrent;

interface DelayedInterface
{
    /**
     * Returns the remaining delay associated with this object, in the
     * given time unit.
     *
     * @return the remaining delay; zero or negative values indicate
     * that the delay has already elapsed
     */
    public function getDelay(): int;
}
