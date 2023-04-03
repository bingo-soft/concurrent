<?php

namespace Concurrent;

interface RunnableInterface
{
    public function run(ThreadInterface $process = null, ...$args): void;
}
