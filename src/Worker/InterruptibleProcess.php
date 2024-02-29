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
    private bool $permit = false;

    public function hasPermit(): bool
    {
        return $this->permit;
    }

    public function setPermit(bool $permit): void
    {
        $this->permit = $permit;
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
        $this->close();
    }

    public function isInterrupted(): bool
    {
        return $this->interrupted;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
