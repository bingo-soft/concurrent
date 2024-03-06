<?php

namespace Tests;

use Concurrent\{
    RunnableInterface,
    ThreadInterface
};
use Concurrent\Lock\NotificationInterface;

class NotifyingTask implements RunnableInterface
{
    private $name;
    private $notification;

    public function __construct(string $name, NotificationInterface $notification)
    {
        $this->name = $name;
        $this->notification = $notification;
    }

    public function __serialize(): array
    {
        return [
            'name' => $this->name
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->name = $data['name'];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(ThreadInterface $process = null, ...$args): void
    {
        fwrite(STDERR, $process->pid . ": Sending notification signal.\n");
        $this->notification->notifyAll($process);
        //fwrite(STDERR, $process->pid . ": Just sent notification signal.\n");
    }
}
