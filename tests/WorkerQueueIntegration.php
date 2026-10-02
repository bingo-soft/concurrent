<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\RunnableInterface;
use Concurrent\ThreadInterface;

final class RecordedTask implements RunnableInterface
{
    public function __construct(private int $id, private string $resultFile, private int $delayMs)
    {
    }

    public function run(ThreadInterface $process = null, ...$args): void
    {
        usleep($this->delayMs * 1000);
        file_put_contents($this->resultFile, $this->id . "\n", FILE_APPEND | LOCK_EX);
    }
}

$total = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if ($total === false || $total < 7 || $total > 2000) {
    fwrite(STDERR, "Usage: php tests/WorkerQueueIntegration.php <7..2000> [producers]\n");
    exit(2);
}
$producerCount = filter_var($argv[2] ?? '1', FILTER_VALIDATE_INT);
if ($producerCount === false || $producerCount < 1 || $producerCount > 8) {
    fwrite(STDERR, "Producer count must be between 1 and 8\n");
    exit(2);
}

$resultFile = tempnam(sys_get_temp_dir(), 'concurrent-worker-');

$pool = new DefaultPoolExecutor(6, 6);
$coresReady = new \Swoole\Atomic\Long(0);
$producerPids = [];
for ($producerIndex = 0; $producerIndex < $producerCount; ++$producerIndex) {
    $producer = new \Swoole\Process(static function () use ($pool, $resultFile, $total, $producerIndex, $producerCount, $coresReady): void {
        try {
            if ($producerIndex !== 0) {
                $deadline = microtime(true) + 3;
                while ($coresReady->get() === 0 && microtime(true) < $deadline) {
                    usleep(10000);
                }
            }
            for ($id = $producerIndex + 1; $id <= $total; $id += $producerCount) {
                $pool->execute(new RecordedTask($id, $resultFile, $id <= 6 ? 300 : 0));
                if ($id === 1) {
                    $coresReady->set(1);
                }
            }
            fwrite(STDERR, "producer=$producerIndex finished\n");
        } catch (\Throwable $error) {
            fwrite(STDERR, "producer=$producerIndex submission_failed=" . $error->getMessage() . "\n");
            exit(2);
        }
        exit(0);
    }, false);
    $producerPids[] = $producer->start();
}

$deadline = microtime(true) + 8;
do {
    $completed = 0;
    $duplicates = [];
    $runs = array_count_values(array_map('intval', file($resultFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    for ($id = 1; $id <= $total; ++$id) {
        if (($runs[$id] ?? 0) > 0) { ++$completed; }
        if (($runs[$id] ?? 0) > 1) { $duplicates[] = $id; }
    }
    if ($completed === $total || $duplicates !== []) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);

fwrite(STDERR, sprintf(
    "completed=%d/%d duplicates=%s pool_failed=%s\n",
    $completed,
    $total,
    implode(',', $duplicates) ?: 'none',
    $pool->isFailed() ? 'yes' : 'no'
));
unlink($resultFile);

if ($completed !== $total || $duplicates !== [] || $pool->isFailed()) {
    exit(1);
}

foreach ($producerPids as $pid) {
    $status = \Swoole\Process::wait(true);
    if ($status === false || $status['code'] !== 0) {
        throw new \RuntimeException('Producer did not finish successfully: ' . $pid);
    }
}
$pool->shutdown();
if (!$pool->isShutdown()) {
    throw new \RuntimeException('Pool did not accept graceful shutdown');
}
exit(0);
