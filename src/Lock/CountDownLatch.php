<?php

namespace Concurrent\Lock;

use Concurrent\TimeUnit;

class CountDownLatch
{ 
    private $sync;

    /**
     * Constructs a {@code CountDownLatch} initialized with the given count.
     *
     * @param count the number of times {@link #countDown} must be invoked
     *        before threads can pass through {@link #await}
     * @throws IllegalArgumentException if {@code count} is negative
     */
    public function __construct(int $count, ?int $port = 1081)
    {
        LockSupport::init($port);
        if ($count < 0) {
            throw new \Exception("count < 0");
        }
        $this->sync = new CountDownLatchSync($count);
    }

    /**
     * Causes the current thread to wait until the latch has counted down to
     * zero, unless the thread is {@linkplain Thread#interrupt interrupted},
     * or the specified waiting time elapses.
     *
     * <p>If the current count is zero then this method returns immediately
     * with the value {@code true}.
     *
     * <p>If the current count is greater than zero then the current
     * thread becomes disabled for thread scheduling purposes and lies
     * dormant until one of three things happen:
     * <ul>
     * <li>The count reaches zero due to invocations of the
     * {@link #countDown} method; or
     * <li>Some other thread {@linkplain Thread#interrupt interrupts}
     * the current thread; or
     * <li>The specified waiting time elapses.
     * </ul>
     *
     * <p>If the count reaches zero then the method returns with the
     * value {@code true}.
     *
     * <p>If the current thread:
     * <ul>
     * <li>has its interrupted status set on entry to this method; or
     * <li>is {@linkplain Thread#interrupt interrupted} while waiting,
     * </ul>
     * then {@link InterruptedException} is thrown and the current thread's
     * interrupted status is cleared.
     *
     * <p>If the specified waiting time elapses then the value {@code false}
     * is returned.  If the time is less than or equal to zero, the method
     * will not wait at all.
     *
     * @param timeout the maximum time to wait
     * @param unit the time unit of the {@code timeout} argument
     * @return {@code true} if the count reached zero and {@code false}
     *         if the waiting time elapsed before the count reached zero
     * @throws InterruptedException if the current thread is interrupted
     *         while waiting
     */
    public function await(?ThreadInterface $thread = null, ?int $time = null, ?string $unit = null)
    {
        if ($time === null && $unit === null) {
            $this->sync->acquireSharedInterruptibly($thread, 1);
            return;
        }
        return $this->sync->tryAcquireSharedNanos($thread, 1, TimeUnit::toNanos($time, $unit));
    }

    /**
     * Decrements the count of the latch, releasing all waiting threads if
     * the count reaches zero.
     *
     * <p>If the current count is greater than zero then it is decremented.
     * If the new count is zero then all waiting threads are re-enabled for
     * thread scheduling purposes.
     *
     * <p>If the current count equals zero then nothing happens.
     */
    public function countDown(?ThreadInterface $thread = null): void
    {
        $this->sync->releaseShared($thread, 1);
    }

    /**
     * Returns the current count.
     *
     * <p>This method is typically used for debugging and testing purposes.
     *
     * @return the current count
     */
    public function getCount(): int
    {
        return $this->sync->getCount();
    }

    /**
     * Returns a string identifying this latch, as well as its state.
     * The state, in brackets, includes the String {@code "Count ="}
     * followed by the current count.
     *
     * @return a string identifying this latch, as well as its state
     */
    public function __toString(): string
    {
        return "[Count = " . $this->sync->getCount() . "]";
    }
}