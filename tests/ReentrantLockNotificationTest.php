<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Concurrent\Queue\ForkJoinWorkQueue;
use Concurrent\Executor\{
    DefaultPoolExecutor,
    ThreadLocalRandom
};
use Concurrent\Lock\ReentrantLockNotification;
use Concurrent\Worker\InterruptibleProcess;

class ReentrantLockNotificationTest extends TestCase
{
    public function testFairReentrantLock(): void
    {
        /*$notification = new ReentrantLockNotification(true);
        
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
        $this->assertTrue(true);*/


        $lock = new \Swoole\Atomic(4294967295);
        /*$p1 = new InterruptibleProcess(function ($process) use ($lock) {
            fwrite(STDERR, "Start process " . $process->pid . ", lock value: " . $lock->get(). ", " . (PHP_INT_MAX) . "\n");
            $lock->wait(-1);
            $lock->add();
            fwrite(STDERR, "Process " . $process->pid . " ended, lock value: " . $lock->get(). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
        });
        $p1->start();*/

        $p2 = new InterruptibleProcess(function ($process) use ($lock) {
            fwrite(STDERR, "Start process " . $process->pid . "\n");
            $lock->wakeup();   
            fwrite(STDERR, "Process " . $process->pid . " ended\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
            fwrite(STDERR, "Process " . $process->pid . " random seed: " . ThreadLocalRandom::nextSecondarySeed($process). "\n");
        });
        $p2->start();

        //$p1->wait();
        $p2->wait();

        echo ">>>" . 0x7fff . "\n\n";
    }
}
