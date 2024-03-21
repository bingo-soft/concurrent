<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Concurrent\Queue\ForkJoinWorkQueue;
use Concurrent\Executor\ForkJoinPool;
use Concurrent\Lock\{
    LockSupport,
    ReentrantLockNotification
};
use Concurrent\Task\ForkJoinTask;
use Concurrent\Worker\InterruptibleProcess;
use Concurrent\Executor\ThreadLocalRandom;

class ForkJoinPoolTest extends TestCase
{
    protected function setUp(): void
    {
    }

    public function testMethods(): void
    {
        //$start = hrtime(true);
        $pool = ForkJoinPool::commonPool(/*$notification*/);
        $result = $pool->invoke(new SumTask(1, 300000));
        //$end = hrtime(true);
        //fwrite(STDERR, getmypid() . ": Result (concurrent) = $result, elapsed: " . ($end - $start) . "\n");
        $this->assertEquals(45000150000, $result);      
    }
}
