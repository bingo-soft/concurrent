<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Concurrent\Queue\ForkJoinWorkQueue;
use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\Lock\ReentrantLockNotification;
use Concurrent\Worker\InterruptibleProcess;

class ReentrantLockNotificationTest extends TestCase
{
    protected function setUp(): void
    {
    }

    public function testFairReentrantLock(): void
    {
        $notification = new ReentrantLockNotification(true);
        
        $waitingTask = new WaitingTask("task 1", $notification);
        $waitingTask2 = new WaitingTask("task 2", $notification);
        $waitingTask3 = new WaitingTask("task 2", $notification);
        $notifyingTask = new NotifyingTask("task 2", $notification);

        $pool = new DefaultPoolExecutor(4);
        $pool->listen(1081);

        $pool->execute($waitingTask);
        $pool->execute($waitingTask2);
        $pool->execute($waitingTask3);
        sleep(1);
        $pool->execute($notifyingTask);
        sleep(5);
        $pool->shutdown();
    }
}
