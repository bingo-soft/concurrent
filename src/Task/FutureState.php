<?php

namespace Concurrent\Task;

enum FutureState
{
    /**
     * The task has not completed.
     */
    case RUNNING;
    /**
     * The task completed with a result.
     * @see Future#resultNow()
     */
    case SUCCESS;
    /**
     * The task completed with an exception.
     * @see Future#exceptionNow()
     */
    case FAILED;
    /**
     * The task was cancelled.
     * @see #cancel(boolean)
     */
    case CANCELLED;
}
