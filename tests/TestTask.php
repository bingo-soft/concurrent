<?php

namespace Tests;

use Concurrent\{
    RunnableInterface,
    ThreadInterface
};

class TestTask implements RunnableInterface
{
    private $name;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function run(ThreadInterface $process = null, ...$args): void
    {
        fwrite(STDERR, getmypid() . ": test task executed\n");
    }
}
