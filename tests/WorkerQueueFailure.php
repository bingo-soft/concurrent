<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\RunnableInterface;
use Concurrent\ThreadInterface;

final class FailedTask implements RunnableInterface
{
    public function run(ThreadInterface $process = null, ...$args): void
    {
        throw new \RuntimeException('unexpected worker failure');
    }
}

$pool = new DefaultPoolExecutor(1, 1);
$pool->execute(new FailedTask());
$deadline = microtime(true) + 3;
while (!$pool->isFailed() && microtime(true) < $deadline) {
    usleep(10000);
}
if (!$pool->isFailed()) {
    throw new \RuntimeException('Unexpected worker failure did not stop the pool');
}
try {
    $pool->execute(new FailedTask());
    throw new \RuntimeException('Failed pool accepted a new task');
} catch (\RuntimeException $exception) {
    if ($exception->getMessage() === 'Failed pool accepted a new task') {
        throw $exception;
    }
}

echo "Unexpected worker failure remains fail-closed\n";
