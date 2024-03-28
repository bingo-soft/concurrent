<?php

namespace Concurrent\Task;

class CoCompletion extends Completion
{
    public $base;

    public function __construct(BiCompletion $base) {
        parent::__construct();
        $this->base = $base;
    }

    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'base' => serialize($this->base)
        ];
        return $ser;
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        $this->base = unserialize($data['base']);
    }

    public function tryFire(int $mode): ?CompletableFuture
    {
        $c = null;
        $d = null;
        if (($c = $this->base) === null || ($d = $c->tryFire($mode)) === null) {
            return null;
        }
        $this->base = null; // detach
        return $d;
    }

    public function isLive(): bool
    {
        $c = null;
        return ($c = $this->base) !== null
            // && c.isLive()
            && $c->dep !== null;
    }
}