<?php

namespace Concurrent\Task;

use Concurrent\{
    ExecutorInterface,
    FutureInterface,
    RunnableInterface,
    TimeUnit
};

interface CompletionStageInterface
{
    public function thenApply(callable $fn): CompletionStageInterface;

    public function thenApplyAsync(callable $fn, ?ExecutorInterface $executor = null): CompletionStageInterface;

    public function thenRun(RunnableInterface | callable $action): CompletionStageInterface;

    public function thenRunAsync(RunnableInterface | callable $action, ?ExecutorInterface $executor = null): CompletionStageInterface;

    public function thenCombine(CompletionStageInterface $other, $fn): CompletionStageInterface;

    public function thenCombineAsync(CompletionStageInterface $other, $fn, ?ExecutorInterface $executor = null): CompletionStageInterface;

    public function runAfterBoth(CompletionStageInterface $other, RunnableInterface | callable $action): CompletionStageInterface;

    public function runAfterBothAsync(CompletionStageInterface $other, RunnableInterface | callable $action, ?ExecutorInterface $executor = null): CompletionStageInterface;

    public function applyToEither(CompletionStageInterface $other, callable $fn): CompletionStageInterface;

    public function applyToEitherAsync(CompletionStageInterface $other, callable $fn, ?ExecutorInterface $executor = null): CompletionStageInterface;

    public function runAfterEither(CompletionStageInterface $other, RunnableInterface | callable $action): CompletionStageInterface;

    public function runAfterEitherAsync(CompletionStageInterface $other, RunnableInterface | callable $action, ?ExecutorInterface $executor = null): CompletionStageInterface;

    public function thenCompose(callable $fn): CompletionStageInterface;

    public function thenComposeAsync(callable $fn, ?ExecutorInterface $executor = null): CompletionStageInterface;

    public function whenComplete($action): CompletionStageInterface;

    public function whenCompleteAsync($action, ?ExecutorInterface $executor = null): CompletionStageInterface;
}
