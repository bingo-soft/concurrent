<?php

namespace Example\Task;

use Phalcon\Cli\Task;
use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\Queue\ArrayBlockingQueue;
use Concurrent\TimeUnit;

class ExampleTask extends Task
{
    public function startAction(): void
    {
        $process1 = new \Swoole\Process(function ($process) {
            fwrite(STDERR, sprintf("Process %d started\n", $process->pid));
        });
        $process1->start();

        $process2 = new \Swoole\Process(function ($process) {
            fwrite(STDERR, sprintf("Process %d started\n", $process->pid));
        });
        $process2->start();

        $workQueue = new ArrayBlockingQueue(10);
        $pool = new DefaultPoolExecutor(3, 0, TimeUnit::MILLISECONDS, $workQueue);
        $pool = new DefaultPoolExecutor(3);
        $task1 = new TestTask("task 1");
        $pool->execute($task1);
        $pool->shutdown();

        $process3 = new \Swoole\Process(function ($process) {
            fwrite(STDERR, sprintf("Process %d started\n", $process->pid));
        });
        $process3->start();

        /*$pool = new DefaultPoolExecutor(3);
        $task1 = new TestTask("task 1");
        $task2 = new TestTask("task 2");
        $task3 = new TestTask("task 3");*/
        /*$task4 = new TestTask("task 4");
        $task5 = new TestTask("task 5");
        $task6 = new TestTask("task 6");*/
        /*$pool->execute($task1);
        $pool->execute($task2);
        $pool->execute($task3);*/
        /*$pool->execute($task4);
        $pool->execute($task5);
        $pool->execute($task6);*/
        /*$pool->shutdown();*/
    }
}
