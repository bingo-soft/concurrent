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
        $notifyingTask = new NotifyingTask("task 2", $notification);

        $pool = new DefaultPoolExecutor(4);
        $pool->listen(1081);

        $pool->execute($waitingTask);
        sleep(2);
        $pool->execute($notifyingTask);
        sleep(5);
        $pool->shutdown();
    }
}
