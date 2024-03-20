<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

interface NotificationInterface
{
    public function await(?ThreadInterface $thread = null, ?int $time = null, ?string $unit = null);

    public function notify(?ThreadInterface $thread = null): void;

    public function notifyAll(?ThreadInterface $thread = null): void;
}
