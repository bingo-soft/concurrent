<?php

namespace Concurrent\Queue;

use Concurrent\{
    ThreadInterface,
    TimeUnit
};
use Concurrent\Lock\ReentrantLock;

class ArrayBlockingQueue extends AbstractQueue implements BlockingQueueInterface
{
    /** The queued items */
    public $items = [];

    /** items index for next take, poll, peek or remove */
    public $takeIndex = 0;

    /** items index for next put, offer, or add */
    public $putIndex = 0;

    /** Number of elements in the queue */
    public $count = 0;

    public $lock;

    public $capacity;

    public const DEFAULT_CAPACITY = 9999;

    private const SHARED_TASK_BYTES = 8192;
    private const WAIT_MICROSECONDS = 10000;
    private const MAX_SHARED_TASKS = 16384;
    private const SHARED_LOCK_TIMEOUT_SECONDS = 2.0;

    private ?\Swoole\Table $sharedTasks = null;
    private ?\Swoole\Atomic\Long $sharedCount = null;
    private ?\Swoole\Atomic\Long $sharedHead = null;
    private ?\Swoole\Atomic\Long $sharedTail = null;
    private ?\Swoole\Atomic\Long $sharedNextTaskId = null;

    public function enableProcessSharing(): void
    {
        if ($this->sharedTasks !== null) {
            return;
        }
        if ($this->count !== 0 || $this->capacity < 1) {
            throw new \LogicException('Process sharing requires an empty queue with positive capacity');
        }
        if ($this->capacity > self::MAX_SHARED_TASKS) {
            throw new \LengthException('Shared worker queue capacity exceeds the supported limit');
        }

        $tableCapacity = 1;
        while ($tableCapacity < $this->capacity) {
            $tableCapacity *= 2;
        }
        $tasks = new \Swoole\Table($tableCapacity);
        $tasks->column('task', \Swoole\Table::TYPE_STRING, self::SHARED_TASK_BYTES);
        $tasks->column('id', \Swoole\Table::TYPE_INT);
        if (!$tasks->create()) {
            throw new \RuntimeException('Unable to create shared worker task queue');
        }
        $mutex = new \Swoole\Lock(SWOOLE_MUTEX);
        $this->sharedTasks = $tasks;
        $this->sharedCount = new \Swoole\Atomic\Long(0);
        $this->sharedHead = new \Swoole\Atomic\Long(0);
        $this->sharedTail = new \Swoole\Atomic\Long(0);
        $this->sharedNextTaskId = new \Swoole\Atomic\Long(0);
        // Shared queue operations must use an OS-level process mutex, not
        // the AQS spin lock used by the in-process collection.
        $this->lock = $mutex;
    }

    public function isProcessShared(): bool
    {
        return $this->sharedTasks !== null;
    }

    private function lockShared(): void
    {
        if (!$this->lock->lockwait(self::SHARED_LOCK_TIMEOUT_SECONDS)) {
            throw new \RuntimeException('Shared worker queue mutex acquisition timed out');
        }
    }

    public function offerWithId($task): ?int
    {
        self::checkNotNull($task);
        if ($this->sharedTasks === null) {
            throw new \LogicException('Task IDs require a process-shared queue');
        }
        $serialized = serialize($task);
        if (strlen($serialized) >= self::SHARED_TASK_BYTES) {
            throw new \LengthException('Serialized worker task exceeds shared queue slot size');
        }
        $this->lockShared();
        try {
            if ($this->sharedCount->get() >= $this->capacity) {
                return null;
            }
            $tail = $this->sharedTail->get();
            $id = $this->sharedNextTaskId->add(1);
            if (!$this->sharedTasks->set((string) $tail, ['task' => $serialized, 'id' => $id])) {
                throw new \RuntimeException('Unable to enqueue worker task');
            }
            $this->sharedTail->set(($tail + 1) % $this->capacity);
            $this->sharedCount->add(1);
            return $id;
        } finally {
            $this->lock->unlock();
        }
    }

    public function removeById(int $id): bool
    {
        if ($this->sharedTasks === null) {
            throw new \LogicException('Task IDs require a process-shared queue');
        }
        $this->lockShared();
        try {
            $values = $this->sharedValues();
            $position = $this->sharedHead->get();
            for ($i = 0; $i < count($values); ++$i) {
                if ($this->sharedTasks->get((string) $position, 'id') === $id) {
                    $this->removeSharedAt($values, $i);
                    return true;
                }
                $position = ($position + 1) % $this->capacity;
            }
            return false;
        } finally {
            $this->lock->unlock();
        }
    }

    private function removeSharedAt(array $values, int $index): void
    {
        $position = $this->sharedHead->get();
        $entries = [];
        for ($i = 0; $i < count($values); ++$i) {
            $entries[] = $this->sharedTasks->get((string) $position);
            $position = ($position + 1) % $this->capacity;
        }
        $remaining = array_values(array_merge(array_slice($entries, 0, $index), array_slice($entries, $index + 1)));
        $position = $this->sharedHead->get();
        foreach ($remaining as $entry) {
            $this->sharedTasks->set((string) $position, $entry);
            $position = ($position + 1) % $this->capacity;
        }
        $this->sharedTasks->del((string) $position);
        $this->sharedTail->set($position);
        $this->sharedCount->sub(1);
    }

    private function sharedRemove(): ?string
    {
        if ($this->sharedCount->get() === 0) {
            return null;
        }
        $head = $this->sharedHead->get();
        $task = $this->sharedTasks->get((string) $head, 'task');
        if ($task === false) {
            throw new \RuntimeException('Shared worker queue lost an accepted task');
        }
        $this->sharedTasks->del((string) $head);
        $this->sharedHead->set(($head + 1) % $this->capacity);
        $this->sharedCount->sub(1);
        return $task;
    }

    private function waitForSharedTask(?ThreadInterface $thread, ?int $deadline = null): ?string
    {
        for (;;) {
            $this->lockShared();
            try {
                $task = $this->sharedRemove();
            } finally {
                $this->lock->unlock();
            }
            if ($task !== null || $thread?->isInterrupted() || ($deadline !== null && hrtime(true) >= $deadline)) {
                return $task;
            }
            $remaining = $deadline === null ? self::WAIT_MICROSECONDS
                : min(self::WAIT_MICROSECONDS, max(1, intdiv($deadline - hrtime(true), 1000)));
            usleep($remaining);
        }
    }

    private function sharedValues(): array
    {
        $values = [];
        $position = $this->sharedHead->get();
        for ($i = 0, $count = $this->sharedCount->get(); $i < $count; ++$i) {
            $value = $this->sharedTasks->get((string) $position, 'task');
            if ($value === false) {
                throw new \RuntimeException('Shared worker queue lost an accepted task');
            }
            $values[] = $value;
            $position = ($position + 1) % $this->capacity;
        }
        return $values;
    }

    /**
     * Circularly increment i.
     */
    public function inc(int $i): int
    {
        $i += 1;
        return ($i === count($this->items)) ? 0 : $i;
    }

    /**
     * Circularly decrement i.
     */
    public function dec(int $i): int
    {
        return (($i === 0) ? count($this->items) : $i) - 1;
    }

    /**
     * Returns item at index i.
     */
    public function itemAt(int $i = null)
    {
        if ($i !== null && $i >= 0 && $i < count($this->items)) {
            return $this->items[$i];
        }
        return null;
    }

    /**
     * Throws NullPointerException if argument is null.
     *
     * @param v the element
     */
    private static function checkNotNull($v = null): void
    {
        if ($v === null) {
            throw new \Exception("Object is null");
        }
    }

    /**
     * Inserts element at current put position, advances
     */
    private function insert($x, ?ThreadInterface $thread = null): void
    {
        $this->items[$this->putIndex] = $x;
        $this->putIndex = $this->inc($this->putIndex);
        $this->count += 1;
        if ($thread !== null) {
            $thread->push(serialize($x));
        }
    }

    public function __construct(int $capacity = self::DEFAULT_CAPACITY, bool $fair = false, $c = null)
    {
        $this->lock = new ReentrantLock(true);//new \Swoole\Lock(SWOOLE_MUTEX);
        if ($capacity < 0) {
            throw new \Exception("Illegal capacity");
        }
        //do not allow capacity to go to infinity
        $this->capacity = $capacity;    
        for ($i = 0; $i < $this->capacity; $i += 1) {
            $this->items[] = null;
        }
        $i = 0;
        $this->lock->lock();
        try {
            if (is_array($c)) {
                foreach ($c as $e) {
                    self::checkNotNull($e);
                    $i += 1;
                    $this->items[$i] = $e;
                }
            }
        } catch (\Exception $ex) {
            throw $ex;
        } finally {
            $this->lock->unlock();
        }
        $this->count = $i;
        $this->putIndex = ($i === $this->capacity) ? 0 : $i;
    }

    public function offer($e, ?ThreadInterface $thread = null): bool
    {
        self::checkNotNull($e);
        if ($this->sharedTasks !== null) {
            return $this->offerWithId($e) !== null;
        }
        $this->lock->lock();
        try {
            if ($this->count === count($this->items)) {
                return false;
            } else {
                $this->insert($e, $thread);
                return true;
            }
        } finally {
            $this->lock->unlock();
        }
    }

    public function poll(?int $timeout = null, ?string $unit = null, ?ThreadInterface $thread = null)
    {
        if ($this->sharedTasks !== null) {
            $nanos = $timeout === null ? 0 : TimeUnit::toNanos($timeout, $unit);
            return $this->waitForSharedTask($thread, hrtime(true) + $nanos);
        }
        $nanos = TimeUnit::toNanos($timeout, $unit);
        $this->lock->lockInterruptibly($thread);
        try {
            time_nanosleep(0, $nanos);
            return $this->normalizeQueueResult($thread->pop());
        } finally {
            $this->lock->unlock();
        }
    }

    public function take(?ThreadInterface $thread = null)
    {
        if ($this->sharedTasks !== null) {
            return $this->waitForSharedTask($thread);
        }
        $this->lock->lock();
        try {
            return $this->normalizeQueueResult($thread->pop());
        } finally {
            $this->lock->unlock();
        }
    }

    private function normalizeQueueResult($value)
    {
        return $value === false ? null : $value;
    }

    public function peek()
    {
        if ($this->sharedTasks !== null) {
            $this->lockShared();
            try {
                $value = $this->sharedTasks->get((string) $this->sharedHead->get(), 'task');
            } finally {
                $this->lock->unlock();
            }
            return $value === false ? null : unserialize($value);
        }
        $this->lock->lock();
        try {
            return ($this->count === 0) ? null : $this->itemAt($this->takeIndex);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Returns the number of elements in this queue.
     *
     * @return int the number of elements in this queue
     */
    public function size(): int
    {
        if ($this->sharedTasks !== null) {
            return $this->sharedCount->get();
        }
        $this->lock->lock();
        try {
            return $this->count;
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Removes a single instance of the specified element from this queue,
     * if it is present.
     *
     * @param o element to be removed from this queue, if present
     * @return {@code true} if this queue changed as a result of the call
     */
    public function remove($o = null)
    {
        if ($this->sharedTasks !== null) {
            $serialized = serialize($o);
            $this->lockShared();
            try {
                $values = $this->sharedValues();
                foreach ($values as $i => $value) {
                    if ($value === $serialized) {
                        $this->removeSharedAt($values, $i);
                        return true;
                    }
                }
                return false;
            } finally {
                $this->lock->unlock();
            }
        }
        $this->lock->lock();
        try {
            if ($o === null) {
                return false;
            }
            for ($i = $this->takeIndex, $k = $this->count; $k > 0; $i = $this->inc($i), $k -= 1) {
                if ($o === $this->items[$i]) {
                    $this->removeAt($i);
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->lock->unlock();
        }
    }

    private function removeAt(int $i): void
    {
        if ($i == $this->takeIndex) {
            $this->items[$this->takeIndex] = null;
            $this->takeIndex = $this->inc($this->takeIndex);
        } else {
            // slide over all others up through putIndex.
            for (;;) {
                $nexti = $this->inc($i);
                if ($nexti != $this->putIndex) {
                    $this->items[$i] = $this->items[$nexti];
                    $i = $nexti;
                } else {
                    $this->items[$i] = null;
                    $this->putIndex = $i;
                    break;
                }
            }
        }
        $this->count -= 1;
    }

    /**
     * Returns {@code true} if this queue contains the specified element.
     *
     * @param o object to be checked for containment in this queue
     * @return {@code true} if this queue contains the specified element
     */
    public function contains($o): bool
    {
        if ($this->sharedTasks !== null) {
            $serialized = serialize($o);
            $this->lockShared();
            try {
                return in_array($serialized, $this->sharedValues(), true);
            } finally {
                $this->lock->unlock();
            }
        }
        $this->lock->lock();
        try {
            if ($o === null) {
                return false;
            }
            for ($i = $this->takeIndex, $k = $this->count; $k > 0; $i = $this->inc($i), $k -= 1) {
                if ($o === $this->items[$i]) {
                    return true;
                }
            }
            return false;
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Returns an array containing all of the elements in this queue, in
     * proper sequence.
     *
     * @return an array containing all of the elements in this queue
     */
    public function toArray(array &$c = null): array
    {
        if ($this->sharedTasks !== null) {
            $this->lockShared();
            try {
                $values = $this->sharedValues();
            } finally {
                $this->lock->unlock();
            }
            $result = array_map('unserialize', $values);
            if ($c !== null) {
                $c = $result;
            }
            return $result;
        }
        $this->lock->lock();
        try {
            if ($c === null) {
                $a = [];
                for ($i = $this->takeIndex, $k = 0; $k < $this->count; $i = $this->inc($i), $k += 1) {
                    $a[$k] = $this->items[$i];
                }
                return $a;
            } elseif (is_array($c)) {
                for ($i = $this->takeIndex, $k = 0; $k < $this->count; $i = $this->inc($i), $k += 1) {
                    $c[$k] = $this->items[$i];
                }
                return $c;
            }
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Atomically removes all of the elements from this queue.
     * The queue will be empty after this call returns.
     */
    public function clear(): void
    {
        if ($this->sharedTasks !== null) {
            $this->lockShared();
            try {
                while ($this->sharedRemove() !== null) {
                }
            } finally {
                $this->lock->unlock();
            }
            return;
        }
        $this->lock->lock();
        try {
            for ($i = $this->takeIndex, $k = $this->count; $k > 0; $i = $this->inc($i), $k -= 1) {
                $this->items[$i] = null;
            }
            $this->count = 0;
            $this->putIndex = 0;
            $this->takeIndex = 0;
        } finally {
            $this->lock->unlock();
        }
    }

    public function drainTo(&$c, int $maxElements = \PHP_INT_MAX): int
    {
        self::checkNotNull($c);
        if ($c === $this) {
            throw new \Exception("Argument must be non-null");
        }
        if ($this->sharedTasks !== null) {
            $this->lockShared();
            try {
                $n = min($this->sharedCount->get(), $maxElements);
                for ($i = 0; $i < $n; ++$i) {
                    $c[] = unserialize($this->sharedRemove());
                }
                return $n;
            } finally {
                $this->lock->unlock();
            }
        }
        $this->lock->trylock();
        try {
            $i = $this->takeIndex;
            $n = 0;
            $max = $maxElements ?? $this->count;
            while ($n < $max) {
                $c[] = $this->items[$i];
                $this->items[$i] = null;
                $i = $this->inc($i);
                $n += 1;
            }
            if ($n > 0 && $maxElements === null) {
                $this->count = 0;
                $this->putIndex = 0;
                $this->takeIndex = 0;
            } elseif ($n > 0) {
                $this->count -= $n;
                $this->takeIndex = $i;
            }
            return $n;
        } finally {
            $this->lock->unlock();
        }
    }

    public function iterator()
    {
        if ($this->sharedTasks !== null) {
            return new \ArrayIterator($this->toArray());
        }
        return new Itr($this);
    }
}
