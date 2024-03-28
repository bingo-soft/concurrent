<?php

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;

try {    
    $pool = new DefaultPoolExecutor(3);
    $task1 = new \Example\TestTask("task 1");
    $task2 = new \Example\TestTask("task 2");
    $task3 = new \Example\TestTask("task 3");
    $task4 = new \Example\TestTask("task 4");
    $task5 = new \Example\TestTask("task 5");
    $task6 = new \Example\TestTask("task 6");
    $pool->execute($task1);
    $pool->execute($task2);
    $pool->execute($task3);
    $pool->execute($task4);
    $pool->execute($task5);
    $pool->execute($task6);
    //$pool->shutdown();
    while (true) {
        sleep(1);
    }
} catch (\Exception $e) {
    fwrite(STDERR, $e->getMessage());
}
