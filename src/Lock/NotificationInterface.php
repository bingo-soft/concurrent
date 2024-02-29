<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

interface NotificationInterface
{
    public function await(ThreadInterface $thread, ?int $time = null, ?string $unit = null);

    public function notify(ThreadInterface $thread): void;

    public function notifyAll(ThreadInterface $thread): void;
}
