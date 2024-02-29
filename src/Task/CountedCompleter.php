<?php

namespace Concurrent\Task;

abstract class CountedCompleter extends ForkJoinTask
{
    //@TODO - ForkJoinPool can work with tasks of other type (like simpler Completion etc.)
}
