<?php

namespace Concurrent\Worker;

use Concurrent\ThreadInterface;

class InterruptibleProcess extends \Swoole\Process implements ThreadInterface
{
    private $interrupted = false;

    public function interrupt(): void
    {
        $this->interrupted = true;
        $this->close();
    }

    public function isInterrupted(): bool
    {
        return $this->interrupted;
    }
}
