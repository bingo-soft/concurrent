<?php

namespace Tests;

class AtomicWrapper
{
    public $tail;

    public function __construct()
    {
        //2.
        //$this->tail = new \Swoole\Atomic\Long(0);

        //3
        //$this->tail = new \Swoole\Atomic\Long(0);
    }

    public function initTail(): void
    {
        //4.
        $this->tail = new \Swoole\Atomic\Long(0);
    }

    public function changeTail(): void
    {
        //1.
        //tail -> will be null in parallel process
        //$this->tail = new \Swoole\Atomic\Long(2);

        //2.
        //tail -> will be still null in parallel process
        //$this->tail = new \Swoole\Atomic\Long(2);

        //3.
        //tail -> it is ok - tail is visible in parallel process
        //$this->tail->set(2);

        //4.
        //tail -> will be still null in parallel process
        $this->tail->set(3);
    }   
}
