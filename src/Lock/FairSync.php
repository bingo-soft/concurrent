<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class FairSync extends Sync
{
    public function lock(ThreadInterface $thread): void
    {
        $this->acquire($thread, 1);
    }

    /**
     * Fair version of tryAcquire.  Don't grant access unless
     * recursive call or no waiters or is first.
     */
    public function tryAcquire(ThreadInterface $current, int $arg): bool
    {
        $c = $this->getState();        
        if ($c == 0) {
            if (!$this->hasQueuedPredecessors($current) && $this->compareAndSetState(0, $arg)) {
                $this->setExclusiveOwnerThread($current);
                //fwrite(STDERR, $current->pid . ": Call tryAcquire and return true, because has no predecessors, state = 0, new state = $arg\n");
                return true;
            }
        } elseif ($current == $this->getExclusiveOwnerThread()) {
            $nextc = $c + $arg;
            if ($nextc < 0) {
                throw new \Exception("Maximum lock count exceeded");
            }
            $this->setState($nextc);
            //fwrite(STDERR, $current->pid . ": Call tryAcquire and return true, because exclusive, state = 0, new state = $nextc\n");
            return true;
        }
        $from = [];
        $ex = new \Exception();
        for ($i = 0; $i < 10; $i += 1) {
            try {
                $t = $ex->getTrace()[$i];
                $from[] = sprintf("%s.%s.%s", $t['file'], $t['function'], $t['line']);
            } catch (\Throwable $tt) {
            }
        }
        //fwrite(STDERR, $current->pid . ": Call of tryAcquire failed, return false, from " . implode(" <= ", $from) . "\n");
        return false;
    }
}
