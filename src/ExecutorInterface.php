<?php

namespace Concurrent;

interface ExecutorInterface
{
    /**
     * Executes the given command at some time in the future.  The command
     * may execute in a new process, in a pooled process, or in the calling
     * process, at the discretion of the <tt>Executor</tt> implementation.
     *
     * @param command the runnable task
     */
    public function execute(RunnableInterface $command): void;

    /**
     * Creates server socket and binds it to specified port. Socket is used
     * for inter process communication to share wait and notify signals
     * (based on PIDS)
     *
     * @param socket either url or socket port
     */
    public function listen(int $port): void;
}
