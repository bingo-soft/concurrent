<?php

namespace Tests;

use Concurrent\{
    RunnableInterface,
    ThreadInterface
};

class ScheduledTask implements RunnableInterface
{
    public $name;

    public function __construct(string $name)
    {
        $this->name = $name;
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

    public function getName()
    {
        return $this->name;
    }

    public function run(ThreadInterface $process = null, ...$args): void
    {
        //Uncomment, if you want to see response from task executed
        //fwrite(STDERR, getmypid() . ": ===============>>>>> task " . $this->name . " executed at " . hrtime(true) . "\n");
    }
}
