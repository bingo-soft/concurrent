<?php

namespace Tests;

use Concurrent\RunnableInterface;

class TestTask implements RunnableInterface, \Serializable
{
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function serialize()
    {
        return json_encode([
            'name' => $this->name
        ]);
    }

    public function unserialize($data)
    {
        $json = json_decode($data);
        $this->name = $json->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(): void
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
        file_put_contents("log.txt", sprintf("Task '%s' completed. Calculated prime number is %s\n", $this->name, $prime), FILE_APPEND);
    }
}
