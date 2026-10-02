<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\RunnableInterface;
use Concurrent\ThreadInterface;

final class OversizedTask implements RunnableInterface
{
    public function __construct(public string $data) {}
    public function run(ThreadInterface $process = null, ...$args): void {}
}

$pool = new DefaultPoolExecutor(1, 1);
$pool->execute(new OversizedTask('first'));
try {
    $pool->execute(new OversizedTask(str_repeat('x', 9000)));
    throw new \RuntimeException('Oversized task was silently accepted');
} catch (\LengthException $exception) {
    if ($pool->getQueue()->size() !== 0) {
        throw new \RuntimeException('Oversized task reserved a shared queue slot');
    }
}

echo "Oversized task rejected without reserving capacity\n";
