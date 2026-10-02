<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;

$pool = new DefaultPoolExecutor(1, 1);
$queue = $pool->getQueue();
$ready = new \Swoole\Atomic\Long(0);
$holder = new \Swoole\Process(static function () use ($queue, $ready): void {
    $queue->lock->lock();
    $ready->set(1);
    sleep(10);
}, false);
$holder->start();

$deadline = microtime(true) + 1;
while ($ready->get() !== 1 && microtime(true) < $deadline) {
    usleep(1000);
}
if ($ready->get() !== 1) {
    throw new \RuntimeException('Mutex holder did not start');
}
\Swoole\Process::kill($holder->pid, SIGKILL);
\Swoole\Process::wait(true);

$start = microtime(true);
try {
    $queue->offer('task');
    throw new \RuntimeException('Dead owner mutex unexpectedly accepted a task');
} catch (\RuntimeException $error) {
    if ($error->getMessage() !== 'Shared worker queue mutex acquisition timed out') {
        throw $error;
    }
}
if (microtime(true) - $start > 3) {
    throw new \RuntimeException('Dead owner detection took too long');
}
echo "Dead mutex owner is detected within timeout\n";
