<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Concurrent\Queue\ArrayBlockingQueue;

$queue = new ArrayBlockingQueue(2);
$queue->enableProcessSharing();

if (!$queue->offer('first') || !$queue->offer('second') || $queue->offer('third')) {
    throw new RuntimeException('Queue capacity was not enforced');
}
if ($queue->size() !== 2 || $queue->peek() !== 'first') {
    throw new RuntimeException('Queue size or FIFO head is incorrect');
}
if (iterator_to_array($queue->iterator()) !== ['first', 'second']) {
    throw new RuntimeException('Shared queue iterator did not expose the queued tasks');
}
if ($queue->poll() !== serialize('first') || $queue->size() !== 1) {
    throw new RuntimeException('Polling did not free exactly one slot');
}
if (!$queue->offer('third') || $queue->size() !== 2) {
    throw new RuntimeException('Freed capacity cannot be reused');
}
if ($queue->poll() !== serialize('second') || $queue->poll() !== serialize('third')) {
    throw new RuntimeException('Queue did not retain FIFO order');
}
if ($queue->poll() !== null || $queue->size() !== 0) {
    throw new RuntimeException('Empty queue did not return null');
}
$start = microtime(true);
if ($queue->poll(25, \Concurrent\TimeUnit::MILLISECONDS) !== null
    || microtime(true) - $start < 0.015) {
    throw new RuntimeException('Timed poll did not wait for the requested deadline');
}

$first = new stdClass();
$first->id = 1;
$second = new stdClass();
$second->id = 2;
$queue->offer($first);
$queue->offer($second);
if (!$queue->remove($first) || $queue->size() !== 1 || $queue->peek() != $second) {
    throw new RuntimeException('Removing a queued task corrupted the queue');
}

$remaining = [];
if ($queue->drainTo($remaining) !== 1 || count($remaining) !== 1 || $remaining[0] != $second || !$queue->isEmpty()) {
    throw new RuntimeException('Draining queued tasks failed');
}

$sameValue = new stdClass();
$sameValue->id = 7;
$firstId = $queue->offerWithId($sameValue);
$secondId = $queue->offerWithId(clone $sameValue);
if ($firstId === $secondId || !$queue->removeById($secondId) || $queue->size() !== 1
    || !$queue->removeById($firstId) || !$queue->isEmpty()) {
    throw new RuntimeException('Rollback removed a different equal-valued task');
}

echo "Shared queue capacity, FIFO, remove and drain passed\n";
