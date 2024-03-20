<?php

namespace Tests;

use Concurrent\Lock\CountDownLatch;
use Concurrent\Worker\InterruptibleProcess;

class ComponentInitializer
{
    private $latch;

    public function __construct(int $components, ?int $port = 1081)
    {
        $this->latch = new CountDownLatch($components, $port);
    }

    public function initializeComponent(callable $func): void
    {
        $process = new InterruptibleProcess(function ($proc) use ($func) {
            try {
                $func();
            } finally {
                $this->latch->countDown();
            }
        });
        $process->start();
    }

    public function awaitInitialization(): void
    {
        $start = hrtime(true);
        $this->latch->await(); // Wait for all components to be initialized
        $end = hrtime(true);
        $duration = floor(($end - $start) / 1000000);
        fwrite(STDERR, getmypid() . ": All components initialized. Application is starting after $duration milliseconds...\n");
    }
}
