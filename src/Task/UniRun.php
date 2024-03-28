<?php

namespace Concurrent\Task;

use Concurrent\{
    ExecutorInterface,
    RunnableInterface,
    ThreadInterface
};
use Opis\Closure\SerializableClosure;

class UniRun extends UniCompletion
{
    public $fn;

    public function __construct(?ExecutorInterface $executor, ?CompletableFuture $dep, ?CompletableFuture $src, RunnableInterface | callable $fn) {
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
    }

    public function tryFire(int $mode): ?CompletableFuture
    {
        $d = null;
        $a = null;
        $r = null;
        $x = null;
        $f = null;
        if (($a = $this->src) === null || (($ra = self::$result->get($a->getXid())))  !== false
            || ($d = $this->dep) === null || ($f = $this->fn) === null) {
            return null;
        }
        if (self::$result->get($d->getXid()) === false) {
            $r = $ra['result'];
            if ($r instanceof AltResult && (($x = $r)->ex) !== null) {
                $d->completeThrowable($x, $r);
            } else {
                try {
                    if ($mode <= 0 && !$this->claim()) {
                        return null;
                    } else {
                        if ($f instanceof RunnableInterface) {
                            $f->run();
                        } elseif (is_callable($f)) {
                            $f();
                        }
                        $d->completeNull();
                    }
                } catch (\Throwable $ex) {
                    $d->completeThrowable($ex);
                }
            }
        }
        //$this->src = null;
        //$this->dep = null;
        //$this->fn = null;
        return $d->postFire($a, $mode);
    }
}
