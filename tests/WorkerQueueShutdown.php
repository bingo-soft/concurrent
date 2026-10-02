<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\RunnableInterface;
use Concurrent\ThreadInterface;

final class ShutdownTask implements RunnableInterface
{
    public function __construct(private int $id, private string $file) {}
    public function run(ThreadInterface $process = null, ...$args): void
    {
        file_put_contents($this->file, $this->id . "\n", FILE_APPEND | LOCK_EX);
        if ($this->id <= 6) {
            usleep(150000);
        }
    }
}

$file = tempnam(sys_get_temp_dir(), 'concurrent-shutdown-');
$pool = new DefaultPoolExecutor(6, 6);
for ($id = 1; $id <= 20; ++$id) {
    $pool->execute(new ShutdownTask($id, $file));
}
$pool->shutdown();

$deadline = microtime(true) + 8;
do {
    $runs = array_count_values(array_map('intval', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    if (count($runs) === 20 || $pool->isFailed()) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);

unlink($file);
if (count($runs) !== 20 || array_filter($runs, static fn (int $count): bool => $count !== 1) || $pool->isFailed()) {
    throw new \RuntimeException('Graceful shutdown lost or duplicated accepted tasks');
}

$deadline = microtime(true) + 3;
while (!$pool->isTerminated() && microtime(true) < $deadline) {
    usleep(20000);
}
if (!$pool->isTerminated()) {
    throw new \RuntimeException('Graceful shutdown left idle workers running');
}
echo "Graceful shutdown completed all 20 accepted tasks\n";
