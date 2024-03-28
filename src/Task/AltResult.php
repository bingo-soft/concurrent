<?php

namespace Concurrent\Task;

class AltResult
{
    public $ex;        // null only for NIL

    public function __construct(?\Throwable $x)
    {
        $this->ex = $x;
    }
}
