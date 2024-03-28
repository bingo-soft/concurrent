<?php

namespace Concurrent\Task;

use Concurrent\ExecutorInterface;

abstract class UniCompletion extends Completion
{
    public $executor;       // executor to use (null if none)
    public $dep;          // the dependent to complete
    public $src;          // source for action

    public function __construct(?ExecutorInterface $executor, ?CompletableFuture $dep, ?CompletableFuture $src)
    {
        parent::__construct();
        $this->executor = $executor;
        $this->dep = $dep;
        $this->src = $src;
    }

    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'dep' => serialize($this->dep),
            'src' => serialize($this->src)
        ];
        return $ser;
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        $this->dep = unserialize($data['dep']);
        $this->src = unserialize($data['src']);
    }

    /**
     * Returns true if action can be run. Call only when known to
     * be triggerable. Uses FJ tag bit to ensure that only one
     * thread claims ownership.  If async, starts as task -- a
     * later call to tryFire will run action.
     */
    public function claim(): bool
    {
        if ($this->compareAndSetForkJoinTaskTag(0, 1)) {
            $e = $this->executor;
            if ($e === null) {
                return true;
            }
            $this->executor = null; // disable
            $e->execute($this);
        }
        return false;
    }

    public function isLive(): bool
    {
        return $this->dep !== null;
    }
}
