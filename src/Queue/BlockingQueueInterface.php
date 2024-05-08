<?php

namespace Concurrent\Queue;

use Concurrent\ThreadInterface;

interface BlockingQueueInterface
{
    public function poll(?int $timeout = null, ?string $unit = null, ?ThreadInterface $thread = null);

    public function take(?ThreadInterface $thread = null);

    public function drainTo(&$c, int $maxElements = \PHP_INT_MAX): int;
}
