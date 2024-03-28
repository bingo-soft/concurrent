<?php

namespace Concurrent\Task;

use Concurrent\ExecutorInterface;
use Opis\Closure\SerializableClosure;

class BiApply extends BiCompletion
{
    public $fn;

    public function __construct(?ExecutorInterface $executor, ?CompletableFuture $dep, ?CompletableFuture $src, ?CompletableFuture $snd, ?callable $fn)
    {
        parent::__construct($executor, $dep, $src, $snd);
        $this->fn = $fn;
    }

    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'dep' => serialize($this->dep),
            'src' => serialize($this->src),
            'snd' => serialize($this->snd)
        ];
        if (is_callable($this->fn)) {
            $ser['fn'] = serialize(new SerializableClosure($this->fn));
        } else {
            $ser['fn'] = null;
        }
        return $ser;
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        if (!empty($data['fn'])) {
            $this->fn = unserialize($data['fn'])->getClosure();
        }
        $this->dep = unserialize($data['dep']);
        $this->src = unserialize($data['src']);
        $this->snd = unserialize($data['snd']);
    }

    public function tryFire(int $mode): ?CompletableFuture
    {
        if (   ($a = $this->src) === null || ($r = CompletableFuture::$result->get($a->getXid())) === false
            || ($b = $this->snd) === null || ($s = CompletableFuture::$result->get($b->getXid())) === false
            || ($d = $this->dep) === null || ($f = $this->fn) === null
            || !$d->biApply($r['result'], $s['result'], $f, $mode > 0 ? null : $this)) {
            return null;
        }
        $this->src = null;
        $this->snd = null;
        $this->dep = null;
        $this->fn = null;
        return $d->postFire($a, $b, $mode);
    }
}