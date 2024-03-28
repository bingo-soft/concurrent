<?php

namespace Concurrent\Task;

class BiRelay extends BiCompletion
{ 
    // for And
    public function __construct(?CompletableFuture $dep, ?CompletableFuture $src, ?CompletableFuture $snd)
    {
        parent::__construct(null, $dep, $src, $snd);
    }

    public function tryFire(int $mode): ?CompletableFuture
    {
        if ( ($a = $this->src) === null || ($r = CompletableFuture::$result->get($a->getXid())) === false
            || ($b = $this->snd) === null || ($s = CompletableFuture::$result->get($b->getXid())) === false
            || ($d = $this->dep) === null) {
            return null;
        }
        if (CompletableFuture::$result->get($d->getXid()) === false) {
            if (($r instanceof AltResult
                 && ($x = ($z = $r)->ex) !== null) ||
                ($s instanceof AltResult
                 && ($x = ($z = $s)->ex) !== null)) {
                $d->completeThrowable($x, $z);
            } else {
                $d->completeNull();
            }
        }
        $this->src = null;
        $this->snd = null;
        $this->dep = null;
        return $d->postFire($a, $b, $mode);
    }
}
