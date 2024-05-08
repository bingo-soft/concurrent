<?php

namespace Concurrent\Task;

use Concurrent\{
    ExecutorServiceInterface,
    RunnableScheduledFutureInterface,
    RunnableInterface,
    ThreadInterface,
    TimeUnit
};
use Concurrent\Queue\AbstractQueue;
use Opis\Closure\SerializableClosure;

class ScheduledFutureTask extends FutureTask implements RunnableScheduledFutureInterface
{
    /** Sequence number to break ties FIFO */
    private $sequenceNumber = 0;

    /**
     * Period for repeating tasks, in nanoseconds.
     * A positive value indicates fixed-rate execution.
     * A negative value indicates fixed-delay execution.
     * A value of 0 indicates a non-repeating (one-shot) task.
     */
    private $period = 0;

    /** The actual task to be re-enqueued by reExecutePeriodic */
    //RunnableScheduledFuture<V> outerTask = this;
    public $outerTask;

    /** The nanoTime-based time when the task is enabled to execute. */
    public static $time;

    /**
     * Index into delay queue, to support faster cancellation.
     */
    public static $heapIndices;

    //Maximum number of active tasks
    public const DEFAULT_MAX_TASKS = 2056;

    /*private $executor;*/

    /**
     * Creates a periodic action with given nanoTime-based initial
     * trigger time and period.
     */
    public function __construct(RunnableInterface | callable $r, ?int $t = 0, ?int $p = 0, ?int $s = 0, ?int $maxTasks = self::DEFAULT_MAX_TASKS)
    {
        parent::__construct($r);
        $this->period = $p ?? 0;
        $this->sequenceNumber = $s;
        $this->outerTask = serialize($this);

        if (self::$heapIndices === null) {
            self::$heapIndices = new \Swoole\Table($maxTasks);
            self::$heapIndices->column('xid', \Swoole\Table::TYPE_STRING, 32);
            self::$heapIndices->column('index', \Swoole\Table::TYPE_INT);
            self::$heapIndices->create();

            self::$time = new \Swoole\Table($maxTasks);
            self::$time->column('xid', \Swoole\Table::TYPE_STRING, 32);
            self::$time->column('time', \Swoole\Table::TYPE_INT);
            self::$time->create();
        }

        self::$time->set($this->xid, ['xid' => $this->xid, 'time' => $t]);
    }

    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'period' => $this->period,
            'sequenceNumber' => $this->sequenceNumber,
            'outerTask' => $this->outerTask
        ];
        if ($this->callable instanceof RunnableInterface) {
            $ser['callable'] = serialize($this->callable);
        } elseif (is_callable($this->callable)) {
            $ser['callable'] = serialize(new SerializableClosure($this->callable));
        } else {
            $ser['callable'] = null;
        }
        return $ser;
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        if (!empty($data['callable'])) {
            $un = unserialize($data['callable']);
            if ($un instanceof RunnableInterface) {
                $this->callable = $un;
            } else {
                $this->callable = $un->getClosure();
            }
        }
        $this->period = $data['period'];
        $this->sequenceNumber = $data['sequenceNumber'];
        $this->outerTask = $data['outerTask'];
    }

    public function getDelay(): int
    {
        return self::$time->get($this->xid, 'time') - hrtime(true);
    }

    public function compareTo($other): int
    {
        if ($other == $this) {// compare zero if same object
            return 0;
        }
        if ($other instanceof ScheduledFutureTask) {
            $diff = self::$time->get($this->xid, 'time') - self::$time->get($other->getXid(), 'time');
            if ($diff < 0) {
                return -1;
            } elseif ($diff > 0) {
                return 1;
            } elseif ($this->sequenceNumber < $other->sequenceNumber) {
                return -1;
            } else {
                return 1;
            }
        }
        $diff = $this->getDelay() - $other->getDelay();
        return ($diff < 0) ? -1 : (($diff > 0) ? 1 : 0);
    }

    /**
     * Returns {@code true} if this is a periodic (not a one-shot) action.
     *
     * @return {@code true} if periodic
     */
    public function isPeriodic(): bool
    {
        return $this->period !== 0;
    }

    /**
     * Sets the next time to run for a periodic task.
     */
    private function setNextRunTime(ExecutorServiceInterface $executor): void
    {
        $p = $this->period;
        if ($p > 0) {
            $next = self::$time->get($this->xid, 'time') + $p;
            self::$time->set($this->xid, ['xid' => $this->xid, 'time' => $next]);
        } else {
            $next = $executor->triggerTime(-$p);
            self::$time->set($this->xid, ['xid' => $this->xid, 'time' => $next]);
        }
    }

    public function cancel(bool $mayInterruptIfRunning, ?ExecutorServiceInterface $executor = null): bool
    {
        // The racy read of heapIndex below is benign:
        // if heapIndex < 0, then OOTA guarantees that we have surely
        // been removed; else we recheck under lock in remove()
        $cancelled = parent::cancel($mayInterruptIfRunning);
        if ($cancelled && $this->removeOnCancel && (($idx = self::$heapIndices->get($this->xid, 'index')) !== false && $idx >= 0) ) {
            $executor->remove($this);
        }
        return $cancelled;
    }

    /**
     * Overrides FutureTask version so as to reset/requeue if periodic.
     */
    public function run(ThreadInterface $worker = null, ...$args): void
    {
        if (!$worker->executor->canRunInCurrentRunState($this)) {
            $this->cancel(false, $worker->executor);
        } elseif (!$this->isPeriodic()) {            
            parent::run($worker, ...$args);
        } elseif (parent::runAndReset()) {
            $this->setNextRunTime($worker->executor);            
            $worker->executor->reExecutePeriodic($this->outerTask);
        }
    }
}
