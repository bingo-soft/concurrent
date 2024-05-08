<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Concurrent\TimeUnit;
use Concurrent\Executor\ScheduledPoolExecutor;
use Concurrent\Queue\DelayedWorkQueue;
use Concurrent\Lock\LockSupport;
use Concurrent\Task\ScheduledFutureTask;
use Concurrent\Worker\InterruptibleProcess;

class DelayedWorkQueueTest extends TestCase
{
    protected function setUp(): void
    {
    }

    public function testQueueMethods(): void
    {
        $queue = new DelayedWorkQueue();

        $this->assertEquals(0, $queue->size());

        $t1 = new ScheduledFutureTask(function () {
            fwrite(STDERR, getmypid() . ": task 1 executed\n");
        });
        $t2 = new ScheduledFutureTask(function () {
            fwrite(STDERR, getmypid() . ": task 2 executed\n");
        });
        $t3 = new ScheduledFutureTask(function () {
            fwrite(STDERR, getmypid() . ": task 3 executed\n");
        });
        $t4 = new ScheduledFutureTask(function () {
            fwrite(STDERR, getmypid() . ": task 4 executed\n");
        });

        $queue->add($t1); //[t1]
        $this->assertEquals(1, $queue->size());

        $queue->add($t2); //[t1, t2]
        $queue->add($t3); //[t1, t2, t3]

        $this->assertEquals(3, $queue->size());

        $queue->remove($t1); //[t2, t3]
        $this->assertEquals(2, $queue->size());

        $this->assertTrue($queue->contains($t3));
        $queue->add($t1); //[t2, t3, t1]
        $this->assertTrue($queue->contains($t1));
        $this->assertTrue($queue->contains($t2));
        $this->assertTrue($queue->contains($t3));
        $this->assertEquals(3, $queue->size());
        $this->assertFalse($queue->contains($t4));

        $t2_ = $queue->peek();
        $this->assertTrue($t2_->equals($t2));

        $queue->offer($t4); //[t2, t3, t1, t4]
        $this->assertEquals(4, $queue->size());

        $t = $queue->poll(); //[t1, t3, t4]
        $this->assertTrue($t->equals($t2));
        $t = $queue->poll(); //[t3, t4]
        $t = $queue->poll();
        $t = $queue->poll();
        $this->assertEquals(0, $queue->size());
        $t = $queue->poll();
    }

    public function testExecutorMethods(): void
    {
        //create pool with 4 workers (processes)
        $executor = new ScheduledPoolExecutor(4);

        $futures = [];
        //5 parallel cycling timers
        for ($i = 1; $i < 5; $i += 1) {
            
            //period in milliseconds
            $period = rand(1, 3);
            $future = $executor->scheduleAtFixedRate(function () use ($period) {
                fwrite(STDERR, getmypid() . ": task executed, period = $period (s), current time = " . hrtime(true) . " (ns)\n");
            }, 0, $period, TimeUnit::SECONDS);
            $futures[] = $future;
        }

        //sleep for 10 seconds and cancel execution
        sleep(8);
        foreach ($futures as $future) {
            $future->cancel(false);
        }   

        $future1 = $executor->schedule(function () {
            fwrite(STDERR, getmypid() . ": delayed task 1 executed, delay = 100 (ms), current time = " . hrtime(true) . " (ns)\n");
        }, 100, TimeUnit::MILLISECONDS);

        $future2 = $executor->schedule(function () {
            fwrite(STDERR, getmypid() . ": delayed task 2 executed, delay = 100 (ms), current time = " . hrtime(true) . " (ns)\n");
        }, 100, TimeUnit::MILLISECONDS);

        $future3 = $executor->schedule(function () {
            fwrite(STDERR, getmypid() . ": delayed task 3 executed, delay = 200 (ms), current time = " . hrtime(true) . " (ns)\n");
        }, 200, TimeUnit::MILLISECONDS);

        $future4 = $executor->schedule(function () {
            fwrite(STDERR, getmypid() . ": delayed task 4 executed, delay = 200 (ms), current time = " . hrtime(true) . " (ns)\n");
        }, 200, TimeUnit::MILLISECONDS);

        $future5 = $executor->schedule(function () {
            fwrite(STDERR, getmypid() . ": delayed task 5 executed, delay = 1000 (ms), current time = " . hrtime(true) . " (ns)\n");
        }, 1000, TimeUnit::MILLISECONDS);

        sleep(2);

        $this->assertTrue(true);
    }
}
