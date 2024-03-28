<?php

namespace Concurrent\Task;

use Opis\Closure\SerializableClosure;

class AnyOf extends Completion
{
    public $dep;
    public $src;
    public $srcs = [];

    public function __construct(?CompletableFuture $dep, ?CompletableFuture $src, ?array $srcs = [])
    {
        parent::__construct();
        $this->dep = $dep;
        $this->src = $src;
        $this->srcs = $srcs;
    }

    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'dep' => serialize($this->dep),
            'src' => serialize($this->src)
        ];
        $srcs = [];
        foreach ($this->srcs as $src) {
            $srcs[] = serialize($src);
        }
        $ser['srcs'] = $srcs;
        return $ser;
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        $this->dep = unserialize($data['dep']);
        $this->src = unserialize($data['src']);
        $this->srcs = [];
        foreach ($data['srcs'] as $src) {
            $this->srcs[] = unserialize($src);
        }
    }

    public function tryFire(int $mode): ?CompletableFuture
    {
        if (($a = $this->src) === null || ($r = CompletableFuture::$result->get($a->getXid())) === false
            || ($d = $this->dep) === null || empty(($as = $this->srcs))) {
            return null;
        }
        $this->src = null;
        $this->dep = null;
        $this->srcs = [];
        if ($d->completeRelay($r['result'])) {
            foreach ($as as $b) {
                if ($b->getXid() !== $a->getXid()) {
                    $b->cleanStack();
                }
            }
            if ($mode < 0) {
                return $d;
            } else {
                $d->postComplete();
            }
        }
        return null;
    }

    public function isLive(): bool
    {
        return ($d = $this->dep) !== null && CompletableFuture::$result->get($d->getXid()) === false;
    }
}