<?php

namespace Concurrent\Queue;

use Concurrent\ThreadInterface;

interface BlockingQueueInterface
{
    /**
     * Returns the next queued value, or null when no value is available.
     */
    public function poll(?int $timeout = null, ?string $unit = null, ?ThreadInterface $thread = null);

    /**
     * Returns the next queued value, or null when the underlying queue is closed.
     */
    public function take(?ThreadInterface $thread = null);

    public function drainTo(&$c, int $maxElements = \PHP_INT_MAX): int;
}
