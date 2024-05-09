<?php

namespace Tests;

use Concurrent\ThreadInterface;
use Concurrent\Task\RecursiveTask;

class SumTask extends RecursiveTask
{
    public $start;
    public $end;
    private const THRESHOLD = 10000; // Arbitrary threshold to split tasks
    public $timestamp;

    public function __construct(int $start, int $end)
    {
        parent::__construct();
        $this->start = $start;
        $this->end = $end;
        $this->timestamp = hrtime(true);
    }

    public function castResult($result)
    {
        return intval($result);
    }

    public function __serialize(): array
    {
        return [
            'xid' => $this->xid,
            'start' => $this->start,
            'end' => $this->end,
            'timestamp' => $this->timestamp,
            'result' => self::$result->get($this->xid, 'result')
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        $this->start = $data['start'];
        $this->end = $data['end'];
        $this->timestamp = $data['timestamp'];
    }

    public function compute(ThreadInterface $worker, ...$args)
    {
        $length = $this->end - $this->start;
        if ($length <= self::THRESHOLD) { // Base case
            $sum = 0;
            for ($i = $this->start; $i <= $this->end; $i++) {
                $sum += $i;
            }
            fwrite(STDERR, getmypid() . ": calculate sum: $sum\n");          
            return $sum;
        } else { // Recursive case
            $mid = $this->start + ($this->end - $this->start) / 2;
            $leftTask = new SumTask($this->start, $mid);
            $rightTask = new SumTask($mid + 1, $this->end);
            $leftTask->fork($worker); // Fork the first task  
            $firstHalf = $rightTask->compute($worker, ...$args);
            $secondHalf = $leftTask->join($worker, ...$args); // Join results
                return $firstHalf + $secondHalf;
        }
    }
}
