<?php

namespace Concurrent\Queue;

class DelayedItr extends \ArrayIterator
{
    public $queue;

    public function __construct(BlockingQueueInterface $queue)
    {
        $this->queue = $queue;
    }

    public function valid(): bool
    {
        return DelayedWorkQueue::$queue->valid();
    }

    public function current()
    {
        return DelayedWorkQueue::$queue->current();
    }

    public function next(): void
    {
        DelayedWorkQueue::$queue->next();
    }

    public function remove(): void
    {
        $key = DelayedWorkQueue::$queue->key();
        if ($key !== null) {
            $this->queue->remove(unserialize(DelayedWorkQueue::$queue->get($key, 'task')));
        }
    }
}
