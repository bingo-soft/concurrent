<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Concurrent\Executor\DefaultPoolExecutor;
use Concurrent\Queue\ArrayBlockingQueue;
use Concurrent\ThreadInterface;
use Concurrent\TimeUnit;

class DefaultPoolExecutorTest extends TestCase
{
    protected function setUp(): void
    {
    }

    public function testBlockingQueue(): void
    {
        $queue = new ArrayBlockingQueue(3);
        $queue->add(1);
        $queue->add(2);
        $queue->add(3);
        $this->assertEquals(3, $queue->size());
        $it = $queue->iterator();
        $this->assertEquals(1, $it->current());
        $this->assertEquals(1, $it->current());
        $this->assertTrue($it->valid());
        while ($it->valid()) {
            $it->next();
        }
        $this->assertEquals(3, $it->current());
        $this->assertFalse($it->valid());

        $queue->clear();
        $this->assertEquals(0, $queue->size());
        $queue->add(1);
        $this->assertEquals(1, $queue->size());
        $queue->remove(2);
        $this->assertEquals(1, $queue->size());
        $queue->remove(1);
        $this->assertEquals(0, $queue->size());
        $queue->add(2);
        $queue->add(3);
        $ar = $queue->toArray();
        $this->assertCount(2, $ar);
    }

    public function testTaskExecution(): void
    {
        $pool = new DefaultPoolExecutor(1, 1);
        $task1 = new TestTask("task 1");
        $pool->execute($task1);
        usleep(100000);
        $pool->shutdown();
        $this->assertTrue($pool->isShutdown());
    }

    public function testTakeReturnsNullWhenProcessQueueIsClosed(): void
    {
        $queue = new ArrayBlockingQueue(1);
        $thread = new QueueResultThread(false);

        $this->assertNull($queue->take($thread));
    }

    public function testTakeReturnsNormalizedSerializedPayload(): void
    {
        $queue = new ArrayBlockingQueue(1);
        $payload = serialize(new TestTask('queued task'));
        $thread = new QueueResultThread($payload);

        $this->assertSame($payload, $queue->take($thread));
    }

    public function testPollReturnsNullWhenProcessQueueIsEmptyOrInterrupted(): void
    {
        $queue = new ArrayBlockingQueue(1);
        $thread = new QueueResultThread(false);

        $this->assertNull($queue->poll(0, TimeUnit::NANOSECONDS, $thread));
    }

    public function testAbruptWorkerFailureStopsPoolWithoutReplacement(): void
    {
        $queueCount = $this->getSystemQueueCount();
        $pool = new DefaultPoolExecutor(1, 1);
        $pool->execute(new FailingTask());

        $deadline = microtime(true) + 2;
        while (!$pool->isFailed() && microtime(true) < $deadline) {
            usleep(10000);
        }

        $this->assertTrue($pool->isFailed());
        $this->assertSame(1, $pool->getFailedWorkerCount());
        $this->assertSame(0, $pool->getPoolSize());
        $this->assertSame($queueCount, $this->getSystemQueueCount());
        $this->assertThrowsRuntimeException(function () use ($pool): void {
            $pool->execute(new TestTask('must be rejected'));
        });
    }

    public function testPhpErrorAlsoStopsPoolWithoutReplacement(): void
    {
        $queueCount = $this->getSystemQueueCount();
        $pool = new DefaultPoolExecutor(1, 1);
        $pool->execute(new FailingTask(true));

        $deadline = microtime(true) + 2;
        while (!$pool->isFailed() && microtime(true) < $deadline) {
            usleep(10000);
        }

        $this->assertTrue($pool->isFailed());
        $this->assertSame(1, $pool->getFailedWorkerCount());
        $this->assertSame(0, $pool->getPoolSize());
        $this->assertSame($queueCount, $this->getSystemQueueCount());
    }

    private function assertThrowsRuntimeException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot execute tasks: worker pool has failed', $exception->getMessage());
        }
    }

    private function getSystemQueueCount(): int
    {
        if (!is_readable('/proc/sysvipc/msg')) {
            $this->markTestSkipped('SysV IPC queue information is not available');
        }

        return count(file('/proc/sysvipc/msg'));
    }
}

class QueueResultThread implements ThreadInterface
{
    private $result;

    public function __construct($result)
    {
        $this->result = $result;
    }

    public function pop()
    {
        return $this->result;
    }

    public function isInterrupted(): bool
    {
        return false;
    }
}
