<?php

namespace Concurrent\Worker;

use Concurrent\{
    TaskInterface,
    ThreadInterface
};
use Util\Net\Socket;

class InterruptibleProcess extends \Swoole\Process implements ThreadInterface
{
    private $interrupted = false;
    private $closed = false;
    private ?\Swoole\Atomic\Long $sharedInterrupted = null;
    private bool $hasQueue = true;

    public function enableSharedInterrupt(): void
    {
        $this->sharedInterrupted = new \Swoole\Atomic\Long(0);
    }

    public function disableQueueCleanup(): void
    {
        $this->hasQueue = false;
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
        $this->sharedInterrupted?->set(1);
        $this->cleanup();
    }

    public function cleanup(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            try {
                if ($this->hasQueue) {
                    $this->freeQueue();
                }
            } finally {
                $this->close();
            }
        }
    }

    public function isInterrupted(): bool
    {
        return $this->interrupted || ($this->sharedInterrupted?->get() === 1);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }
}
