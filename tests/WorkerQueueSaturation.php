<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\Queue\ArrayBlockingQueue;
use Concurrent\RunnableInterface;
use Concurrent\ThreadInterface;

final class SaturationTask implements RunnableInterface
{
    public function __construct(private int $id, private string $file) {}

    public function run(ThreadInterface $process = null, ...$args): void
    {
        file_put_contents($this->file, $this->id . "\n", FILE_APPEND | LOCK_EX);
        usleep(500000);
    }
}

$file = tempnam(sys_get_temp_dir(), 'concurrent-saturation-');
$queue = new ArrayBlockingQueue(2);
$pool = new DefaultPoolExecutor(6, 6, 0, \Concurrent\TimeUnit::MILLISECONDS, $queue);
for ($id = 1; $id <= 8; ++$id) {
    $pool->execute(new SaturationTask($id, $file));
}
if ($queue->size() !== 2) {
    throw new \RuntimeException('Queue failed to enforce its two-task capacity');
}
try {
    $pool->execute(new SaturationTask(9, $file));
    throw new \RuntimeException('Saturated executor silently accepted the ninth task');
} catch (\RuntimeException $error) {
    if ($error->getMessage() === 'Saturated executor silently accepted the ninth task') {
        throw $error;
    }
}

$deadline = microtime(true) + 5;
do {
    $runs = array_count_values(array_map('intval', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    if (count($runs) === 8) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);

unlink($file);
if (count($runs) !== 8 || isset($runs[9]) || array_filter($runs, static fn (int $n): bool => $n !== 1)) {
    throw new \RuntimeException('Saturation lost an accepted task or ran a rejected task');
}
$pool->shutdown();
echo "Full queue rejects excess task; eight accepted tasks finish once\n";
