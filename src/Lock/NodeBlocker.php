<?php

namespace Concurrent\Lock;

use Concurrent\Executor\ManagedBlockerInterface;

class NodeBlocker implements ManagedBlockerInterface
{
    public $id;
    public $waiter;
    public $cond;

    public function __construct(array $node, ConditionInterface $cond)
    {
        $this->id = $node['id'];
        $this->waiter = $node['waiter'];
        $this->cond = $cond;
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->node['id'],
            'waiter' => $this->node['waiter']
        ];
    }

    public function __unserialize(array $data): void
    {        
        $this->id = $data['id'];
        $this->waiter = $data['waiter'];
    }

    public function isReleasable(): bool
    {
        // || Thread.currentThread().isInterrupted()
        return $this->cond->queue->get((string) $this->id, 'status') <= 1;
    }

    public function block(): bool
    {
        while (!$this->isReleasable()) {
            LockSupport::park(null);
        }
        return true;
    }
}
