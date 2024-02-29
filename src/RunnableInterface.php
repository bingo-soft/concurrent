<?php

namespace Concurrent;

interface RunnableInterface extends TaskInterface
{
    public function run(ThreadInterface $process = null, ...$args): void;
}
