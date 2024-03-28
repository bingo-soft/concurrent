<?php

namespace Concurrent\Task;

use Concurrent\{
    ExecutorInterface,
    RunnableInterface
};
use Opis\Closure\SerializableClosure;

class OrRun extends BiCompletion
{
    public $fn;

    public function __construct(?ExecutorInterface $executor, ?CompletableFuture $dep, ?CompletableFuture $src, ?CompletableFuture $snd, RunnableInterface | callable $fn)
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
        if ($this->fn instanceof RunnableInterface || is_callable($this->fn)) {
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
        if (   ($a = $this->src) === null || ($b = $this->snd) === null ||
             (($res = CompletableFuture::$result->get($a->getXid())) === false
            && ($res = CompletableFuture::$result->get($b->getXid())) === false)
            || ($d = $this->dep) === null || ($f = $this->fn) === null) {
            return null;
        }
        if (CompletableFuture::$result->get($d->getXid()) === false) {
            $r = $res['result'];
            try {
                if ($mode <= 0 && !$this->claim()) {
                    return null;
                } elseif ($r instanceof AltResult && ($x = $r->ex) !== null) {
                    $d->completeThrowable($x, $r);
                } else {
                    if ($f instanceof RunnableInterface) {
                        $f->run();
                    } else {
                        $f();
                    }
                    $d->completeNull();
                }
            } catch (\Throwable $ex) {
                $d->completeThrowable($ex);
            }
        }
        $this->src = null;
        $this->snd = null;
        $this->dep = null;
        $this->fn = null;
        return $d->postFire($a, $b, $mode);
    }
}
