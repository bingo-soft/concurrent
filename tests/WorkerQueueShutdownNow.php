<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\RunnableInterface;
use Concurrent\ThreadInterface;

final class ShutdownNowTask implements RunnableInterface
{
    public function __construct(public int $id) {}
    public function run(ThreadInterface $process = null, ...$args): void
    {
        usleep(1000000);
    }
}

$pool = new DefaultPoolExecutor(6, 6);
for ($id = 1; $id <= 20; ++$id) {
    $pool->execute(new ShutdownNowTask($id));
}
$queuedBefore = $pool->getQueue()->size();
$pending = $pool->shutdownNow();
$ids = array_map(static fn (ShutdownNowTask $task): int => $task->id, $pending);
sort($ids);
echo 'queued_before=' . $queuedBefore . ' drained=' . count($pending)
    . ' remaining=' . $pool->getQueue()->size() . PHP_EOL;
if ($queuedBefore !== 14 || count($pending) !== 14 || $ids !== range(7, 20) || !$pool->getQueue()->isEmpty()) {
    throw new \RuntimeException('shutdownNow lost queued tasks or drained running tasks');
}
