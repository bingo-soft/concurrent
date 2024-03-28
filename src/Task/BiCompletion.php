<?php

namespace Concurrent\Task;

use Concurrent\ExecutorInterface;

abstract class BiCompletion extends UniCompletion
{
    public $snd; // second source for action

    public function __construct(?ExecutorInterface $executor, ?CompletableFuture $dep, ?CompletableFuture $src, ?CompletableFuture $snd)
    {
        parent::__construct($executor, $dep, $src);
        $this->snd = $snd;
    }

    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'dep' => serialize($this->dep),
            'src' => serialize($this->src),
            'snd' => serialize($this->snd)
        ];
        return $ser;
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        $this->dep = unserialize($data['dep']);
        $this->src = unserialize($data['src']);
        $this->snd = unserialize($data['snd']);
    }
}