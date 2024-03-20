<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class CountDownLatchSync extends AbstractQueuedSynchronizer
{
    public function __construct(int $count)
    {
        parent::__construct();
        $this->setState($count);
    }

    public function getCount(): int
    {
        return $this->getState();
    }

    public function tryAcquireShared(?ThreadInterface $thread = null, int $arg = 0): int
    {
        return ($this->getState() === 0) ? 1 : -1;
    }

    public function tryReleaseShared(?ThreadInterface $thread = null, int $arg = 0): bool
    {
        // Decrement count; signal when transition to zero
        for (;;) {
            $c = $this->getState();
            if ($c === 0) {
                return false;
            }
            $nextc = $c - 1;
            if ($this->compareAndSetState($c, $nextc)) {
                return $nextc === 0;
            }
        }
    }
}
