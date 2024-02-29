<?php

namespace Concurrent\Queue;

use Concurrent\Executor\ForkJoinPoolExecutor;
use Concurrent\Worker\ForkJoinWorkerProcess;
use Concurrent\Task\{
    EmptyTask,
    ForkJoinTask
};

class ForkJoinWorkQueue
{
    /**
     * Capacity of work-stealing queue array upon initialization.
     */
    public const INITIAL_QUEUE_CAPACITY = 1 << 8;

    /**
     * Maximum size for queue arrays.
     */
    public const MAXIMUM_QUEUE_CAPACITY = 1 << 16;

    //8KB per serialized task
    public const DEFAULT_SIZE = 8192;

    // Instance fields
    public int $scanState = 0;    // versioned, <0: inactive; odd:scanning
    public int $stackPred = 0;    // pool stack (ctl) predecessor
    public int $nsteals = 0;      // number of steals
    public int $hint = 0;         // randomization and stealer index hint
    public int $config = 0;       // pool index and mode
    public $qlock;                // 1: locked, < 0: terminate; else 0
    public $base;                 // index of next slot for poll
    public $top;                  // index of next slot for push
    public $array;                // the elements (initially unallocated)
    public $pool;                 // the containing pool (may be null)
    public $owner;                // owning thread or null if shared
    public $parker;               // == owner during call to park; else null
    public $currentJoin;          // task being joined in awaitJoin
    public $currentSteal;         // mainly used by helpStealer
    public $capacity;             // custom size of queue array
    public $allocated;            // allocation of the queue is set by pool
    private $stealCounter;        // steal counter is managed in outer context (that is pool)
    
    public function __construct(ForkJoinPoolExecutor $pool = null, ForkJoinWorkerProcess $owner = null, int $capacity = self::MAXIMUM_QUEUE_CAPACITY, int $size = self::DEFAULT_SIZE, \Swoole\Atomic\Long $stealCounter = new \Swoole\Atomic\Long(0))
    {
        $this->pool = $pool;
        $this->owner = $owner;
        $this->capacity = $capacity;
        $this->stealCounter = $stealCounter;

        $array = new \Swoole\Table($capacity);
        $array->column('task', \Swoole\Table::TYPE_STRING, $size);
        $array->create();
        $this->array = $array;

        $steal = new \Swoole\Table(1);
        $steal->column('task', \Swoole\Table::TYPE_STRING, $size);
        $steal->create();
        $this->currentSteal = $steal;
        
        $this->qlock = new \Swoole\Atomic\Long(0);        
        $this->top = new \Swoole\Atomic\Long(0);
        $this->base = new \Swoole\Atomic\Long(0);
        $this->allocated = new \Swoole\Atomic\Long(0);
    }

    public function setAllocated(): void
    {
        $this->allocated->set(1);
    }

    public function isAllocated(): int
    {
        return $this->allocated->get();
    }

    /**
     * Returns an exportable index (used by ForkJoinWorkerThread).
     */
    public function getPoolIndex(): int
    {
        return $this->uRShift(($this->config & 0xffff), 1); // ignore odd/even tag bit
    }

    private function uRShift(int $a, int $b): int
    {
        if ($b == 0) {
            return $a;
        }
        return ($a >> $b) & ~(1<<(8*PHP_INT_SIZE-1)>>($b-1));
    }

    /**
     * Returns the number of tasks in the queue.
     */
    public function queueSize(): int
    {
        return $this->array->count();
    }    

    /**
    * Checks if task queue is empty or not
    */
    public function isEmpty(): bool
    {
        return $this->array->count() == 0;
    }

    /**
     * Pushes a task. Call only by owner in unshared queues.  (The
     * shared-queue version is embedded in method externalPush.)
     *
     * @param task the task. Caller must ensure non-null.
     * @throws \Exception if array cannot be resized
     */
    public function push(ForkJoinTask $task): void
    {
        if ($this->array->count() == $this->capacity) {
            throw new \Exception("Queue is full");
        }
        $base = $this->base->get();
        $top = $this->top->get();
        $nextTop = ($top + 1) % $this->capacity;
        $this->array->set((string) $top, ['task' => serialize($task)]);
        $this->top->set($nextTop);
    }

    /**
     * Takes next task, if one exists, in LIFO order.  Call only
     * by owner in unshared queues.
     */
    public function pop(): ?ForkJoinTask
    {
        if ($this->isEmpty()) {
            return null;
        }

        $currentTop = $this->top->get();
        $newTop = ($currentTop - 1 + $this->capacity) % $this->capacity;

        $this->top->set($newTop);
        $task = $this->array->get((string) $newTop, 'task');
        $this->array->del((string) $newTop);
        return unserialize($task);
    }

    /**
     * Takes next task, if one exists, in FIFO order.
     */
    public function poll(): ?ForkJoinTask
    {
        if ($this->isEmpty()) {
            return null;
        }
        $base = $this->base->get();
        $task = $this->array->get((string) $base, 'task');
        $this->array->del((string) $base);
        $this->base->set(($base + 1) % $this->capacity);
        return unserialize($task);
    }

    /**
     * Takes a task in FIFO order if index is base of queue and a task
     * can be claimed without contention. Specialized versions
     * appear in ForkJoinPoolExecutor methods scan and helpStealer.
     */
    public function pollAt(int $index): ?ForkJoinTask
    {
        $base = $this->base->get();
        $actualIndex = ($base + $index) % $this->capacity;
        if ($actualIndex == $this->base->get()) {
            return $this->poll();
        } else {
            throw new \Exception("Invalid index $index provided");
        }
    }

    /**
     * Takes next task, if one exists, in order specified by mode.
     */
    public function nextLocalTask(): ?ForkJoinTask
    {
        return ($this->config & ForkJoinPoolExecutor::FIFO_QUEUE) == 0 ? $this->pop() : $this->poll();
    }

    /**
     * Returns next task, if one exists, in order specified by mode.
     */
    public function peek(): ?ForkJoinTask
    {
        if ($this->isEmpty()) {
            return null;
        }

        if (($this->config & ForkJoinPoolExecutor::FIFO_QUEUE) == 0) {
            $newTop = ($this->top->get() - 1 + $this->capacity) % $this->capacity;
            $task = $this->array->get((string) $newTop, 'task');
            return unserialize($task);
        } else {
            $task = $this->array->get((string) $this->base->get(), 'task');
            return unserialize($task);
        }       
    }

    /**
     * Pops the given task only if it is at the current top.
     * (A shared version is available only via FJP.tryExternalUnpush)
    */
    public function tryUnpush(ForkJoinTask $curTask): bool
    {
        if (!$this->isEmpty()) {
            $currentTop = $this->top->get();
            $newTop = ($currentTop - 1 + $this->capacity) % $this->capacity;            
            $task = unserialize($this->array->get((string) $newTop, 'task'));
            if ($task == $curTask || (method_exists($task, 'equals') && $task->equals($curTask))) {
                $this->top->set($newTop);
                $this->array->del((string) $newTop);
                return true;
            }
        }
        return false;
    }

    /**
     * Removes and cancels all known tasks, ignoring any exceptions.
     */
    public function cancelAll(): void
    {
        $t = null;
        if (($t = $this->currentJoin) !== null) {
            $this->currentJoin = null;
            ForkJoinTask::cancelIgnoringExceptions($t);
        }
        if ($this->currentSteal !== null && ($t = $this->currentSteal->get('task') !== null)) {
            $this->currentSteal->del('task');
            ForkJoinTask::cancelIgnoringExceptions(unserialize($t));
        }
        while (($t = $this->poll()) !== null) {
            ForkJoinTask::cancelIgnoringExceptions(unserialize($t));
        }
    }

    /**
     * Polls and runs tasks until empty.
     */
    public function pollAndExecAll(): void
    {
        while (($t = $this->poll()) !== null) {
            $t->doExec();
        }
    }

    /**
     * Removes and executes all local tasks. If LIFO, invokes
     * pollAndExecAll. Otherwise implements a specialized pop loop
     * to exec until empty.
     */
    public function execLocalTasks(): void
    {
        if (!$this->isEmpty()) {
            if (($this->config & ForkJoinPoolExecutor::FIFO_QUEUE) == 0) {
                while (($t = $this->pop()) !== null) {
                    $t->doExec();
                }
            } else {
                $this->pollAndExecAll();
            }
        }
    }

    /**
     * Executes the given task and any remaining local tasks.
     */
    public function runTask(?ForkJoinTask $task): void
    {
        if ($task !== null) {
            $this->scanState &= ~ForkJoinPoolExecutor::SCANNING; // mark as busy
            $this->currentSteal->set('task', serialize($task));
            $task->doExec();
            $this->currentSteal->del('task');
            $this->execLocalTasks();
            $thread = $this->owner;
            if (++$this->nsteals < 0) { // collect on overflow
                $this->transferStealCount($this->pool);
            }
            $this->scanState |= ForkJoinPoolExecutor::SCANNING;
            if ($thread != null) {
                $thread->afterTopLevelExec();
            }
        }
    }

    /**
     * Adds steal count to pool stealCounter if it exists, and resets.
     */
    public function transferStealCount(?ForkJoinPoolExecutor $p): void
    {
        if ($p != null && $this->stealCounter->get() !== 0) {
            $s = $this->nsteals;
            $this->nsteals = 0;  // if negative, correct for overflow
            $this->stealCounter->add($s < 0 ? PHP_INT_MAX : $s);
        }
    }

    /**
     * If present, removes from queue and executes the given task,
     * or any other cancelled task. Used only by awaitJoin.
     *
     * @return true if queue empty and task not known to be done
     */
    public function tryRemoveAndExec(?ForkJoinTask $task): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        $currentTop = $this->top->get();
        $baseTask = unserialize($this->array->get((string) $this->base->get(), 'task'));
        $baseInit = $this->base->get();
        for ($i = 1; ; $i += 1) {            
            $newTop = ($currentTop - $i + $this->capacity) % $this->capacity;
            $curTask = unserialize($this->array->get((string) $newTop, 'task'));
            if ($task == $curTask || (method_exists($task, 'equals') && $task->equals($curTask))) {
                $removed = false;
                if ($i == 1) { // pop
                    $this->top->set($newTop);
                    $this->array->del((string) $newTop);
                    $removed = true;
                } elseif ($this->base->get() == $baseInit) {// replace with proxy
                    //double check base, because it could change in parallel processes
                    $this->array->set((string) $newTop, ['task' => serialize(new EmptyTask())]);
                    $removed = true;
                }
                if ($removed) {
                    $task->doExec();
                }
                break;
            } elseif ($t->status < 0 && $i == 1) {
                $this->top->set($newTop);
                $this->array->del((string) $newTop);
                break; // was cancelled
            }

            if ($curTask == $baseTask) {
                return false;
            }
        }
        if ($task->status < 0) {
            return false;
        }
        return true;
    }
}
