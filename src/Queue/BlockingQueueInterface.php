<?php

namespace Concurrent\Queue;

use Concurrent\ThreadInterface;

interface BlockingQueueInterface
{
    public function poll(int $timeout, string $unit, ?ThreadInterface $thread = null);

    public function take(?ThreadInterface $thread = null);

    public function drainTo(&$c, int $maxElements = null): int;
}
