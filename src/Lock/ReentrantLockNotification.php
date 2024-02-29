<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class ReentrantLockNotification implements NotificationInterface
{
    public $lock;
    private $condition;

    public function __construct(bool $fair = false)
    {
        $this->lock = new ReentrantLock($fair);
        $this->condition = $this->lock->newCondition();
    }
    
    public function await(ThreadInterface $thread, ?int $time = null, ?string $unit = null)
    {
        //Thread in this and other methods represents just current thread (aka Thread.current())
        $this->lock->lock($thread);
        try {
            $this->condition->await($thread, $time, $unit);
        } finally {
            $this->lock->unlock($thread);
        }
    }

    public function notify(ThreadInterface $thread): void
    {
        $this->lock->lock($thread);
        try {
            $this->condition->signal($thread);
        } finally {
            $this->lock->unlock($thread);
        }
    }

    public function notifyAll(ThreadInterface $thread): void
    {
        $this->lock->lock($thread);
        try {
            $this->condition->signalAll($thread);
        } finally {
            $this->lock->unlock($thread);
        }
    }
}
