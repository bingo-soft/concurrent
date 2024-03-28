<?php

namespace Concurrent\Task;

use Concurrent\ExecutorInterface;

class UniRelay extends UniCompletion
{
    public $fn;

    public function __construct(?ExecutorInterface $executor, ?CompletableFuture $dep, ?CompletableFuture $src)
    {
        parent::__construct($executor, $dep, $src);
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

    public function tryFire(int $mode): ?CompletableFuture
    {
        if ( ($a = $this->src) === null || ($res = CompletableFuture::$result->get($a->getXid())) === false
                || ($d = $this->dep) === null) {
            return null;
        }
        if (CompletableFuture::$result->get($d->getXid()) === false) {
            $d->completeRelay($r['result']);
        }
        $this->src = null;
        $this->dep = null;
        return $d->postFire($a, $mode);
    }
}