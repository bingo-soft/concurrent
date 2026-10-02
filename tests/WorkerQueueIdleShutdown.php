<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;

$pool = new DefaultPoolExecutor(2, 2);
$pool->prestartAllCoreThreads();
usleep(100000);
$pool->shutdown();
$deadline = microtime(true) + 3;
while (!$pool->isTerminated() && microtime(true) < $deadline) {
    usleep(10000);
}
if (!$pool->isTerminated()) {
    throw new RuntimeException('Idle workers did not terminate after shutdown');
}
echo "Idle workers terminate after shutdown\n";
