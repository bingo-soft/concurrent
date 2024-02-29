<?php

namespace Tests;

use Swoole\Process;
use Swoole\Event;
use Swoole\Coroutine\Channel;
use function Swoole\Coroutine\run;

class TestProcess extends Process
{
    private bool $permit = false;
    private Channel $eventBus;

    public function __construct(Channel $eventBus, $function)
    {
        parent::__construct($function,
            // redirect_stdin_and_stdout
            false,
            // pipe_type
            SOCK_DGRAM,
            // enable_coroutine
            true
        );
        $this->eventBus = $eventBus;
    }

    public function park()
    {
        if ($this->permit) {
            $this->permit = false;
            return;
        }

        Event::add($this->exportSocket(), function ($socket) {
            $data = $this->eventBus->pop();
            if ($data && $data['targetPid'] === $this->pid) {
                $this->permit = true;
                Event::del($socket);
            }
        });

        while (!$this->permit) {
            Event::dispatch();
        }
    }

    public static function unpark(Channel $eventBus, int $targetPid): void
    {
        run(function () use ($eventBus, $targetPid) {
            $eventBus->push(['targetPid' => $targetPid]);
        });
    }
}
