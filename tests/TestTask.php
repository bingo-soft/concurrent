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
        $count = 0;
        $num = 2;
        $prime = null;
        $divs;
        while ($count < 2000) {
            $divs = 0;
            for ($i = 1; $i <= $num; $i += 1) {
                if (($num % $i) == 0) {
                    $divs += 1;
                }
            }
            if ($divs < 3) {
                $prime = $num;
                $count += 1;
            }
            $num += 1;
        }
    }
}
