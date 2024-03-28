<?php

namespace Concurrent\Task;

use Concurrent\ExecutorInterface;
use Opis\Closure\SerializableClosure;

class UniWhenComplete extends UniCompletion
{
    private $fn = null;

    public function __construct(?ExecutorInterface $executor, ?CompletableFuture $dep, ?CompletableFuture $src, $fn) {
        parent::__construct($executor, $dep, $src);
        $this->fn = $fn;
    }

    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'dep' => serialize($this->dep),
            'src' => serialize($this->src)
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
    }

    public function tryFire(int $mode): ?CompletableFuture
    {
        if (($a = $this->src) === null || ($res = CompletableFuture::$result->get($a->getXid())) === false
            || ($d = $this->dep) === null || ($f = $this->fn) === null
            || !$d->uniWhenComplete($res['result'], $f, $mode > 0 ? null : $this)) {
            return null;
        }
        $this->src = null;
        $this->dep = null;
        $this->fn = null;
        return $d->postFire($a, $mode);
    }
}