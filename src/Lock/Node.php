<?php

namespace Concurrent\Lock;

use Concurrent\ThreadInterface;

class Node
{
    /** Marker to indicate a node is waiting in shared mode */
    public static $SHARED;// = new Node();

    /** Marker to indicate a node is waiting in exclusive mode */
    public static $EXCLUSIVE = null;

    /** waitStatus value to indicate thread has cancelled */
    public const CANCELLED = 1;

    /** waitStatus value to indicate successor's thread needs unparking */
    public const SIGNAL    = -1;

    /** waitStatus value to indicate thread is waiting on condition */
    public const CONDITION = -2;

    /**
     * waitStatus value to indicate the next acquireShared should
     * unconditionally propagate
     */
    public const PROPAGATE = -3;

    private function __construct()
    {
    }
}
