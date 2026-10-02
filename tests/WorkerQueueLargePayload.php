<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\RunnableInterface;
use Concurrent\ThreadInterface;

final class LargePayloadTask implements RunnableInterface
{
    public function __construct(private string $value, private string $file) {}

    public function run(ThreadInterface $process = null, ...$args): void
    {
        file_put_contents($this->file, strlen($this->value) . "\n", FILE_APPEND | LOCK_EX);
        usleep(250000);
    }
}

$file = tempnam(sys_get_temp_dir(), 'concurrent-payload-');
$pool = new DefaultPoolExecutor(6, 6);
for ($id = 1; $id <= 6; ++$id) {
    $pool->execute(new LargePayloadTask('first', $file));
}
$pool->execute(new LargePayloadTask(str_repeat('x', 8000), $file));

$deadline = microtime(true) + 5;
do {
    $lengths = array_map('intval', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    if (count($lengths) === 7) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);

unlink($file);
if (count($lengths) !== 7 || !in_array(8000, $lengths, true)) {
    throw new \RuntimeException('Shared worker transport truncated or lost a large task');
}
echo "Large serialized worker task delivered completely\n";
