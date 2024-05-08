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
        $nanos = TimeUnit::toNanos($timeout, $unit);
        $this->lock->lockInterruptibly();
        try {
            time_nanosleep(0, $nanos);
            return $thread->pop();
        } finally {
            $this->lock->unlock();
        }
    }

    public function take(?ThreadInterface $thread = null)
    {
        $this->lock->lock();
        try {
            return $thread->pop();
        } finally {
            $this->lock->unlock();
        }
    }

    public function peek()
    {
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
        return new Itr($this);
    }
}
