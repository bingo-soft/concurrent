<?php

namespace Concurrent\Task;

use Concurrent\{
    RunnableInterface,
    ThreadInterface
};
use Opis\Closure\SerializableClosure;

class AsyncRun extends ForkJoinTask implements RunnableInterface, AsynchronousCompletionTaskInterface
{
    public $dep;
    public $fn;
    public $thread;

    public function __construct(CompletableFuture $dep, $fn)
    {
        parent::__construct();
        $this->dep = $dep;
        $this->fn = $fn;
    }
    
    public function __serialize(): array
    {
        $ser = [
            'xid' => $this->xid,
            'dep' => serialize($this->dep)
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
    }

    public function exec(?ThreadInterface $thread, ...$args): bool
    {
        $this->thread = $thread;
        $this->run($thread, ...$args);
        return false;
    }

    public function getRawResult()
    {
        return null;
    }

    public function setRawResult($v): void
    {
    }

    public function run(ThreadInterface $process = null, ...$args): void
    {
        $d = null;
        $f = null;        
        if (($d = $this->dep) !== null && ($f = $this->fn) !== null) {
            $this->dep = null;
            $this->fn = null;
            if (self::$result->get($d->getXid()) === false) {
                try {
                    if ($f instanceof RunnableInterface) {
                        $f->run($process, ...$args);
                    } else {
                        $f($process, ...$args);
                    }
                    $d->completeNull();
                } catch (\Throwable $ex) {
                    $d->completeThrowable($ex);
                }
            }
            $d->postComplete();
        }
    }
}
