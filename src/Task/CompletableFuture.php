<?php

namespace Concurrent\Task;

use Concurrent\{
    ExecutorInterface,
    FutureInterface,
    RunnableInterface,
    TimeUnit
};
use Concurrent\Lock\{
    LockSupport,
    ReentrantLockNotification
};
use Concurrent\Executor\{
    DefaultPoolExecutor,
    ForkJoinPool,
    ThreadLocalRandom
};

class CompletableFuture implements FutureInterface, CompletionStageInterface
{
    //8KB per serialized task, should be enough
    public const DEFAULT_SIZE = 8192;

    //Maximum number of dependent tasks
    public const DEFAULT_MAX_DEPENDENT_TASKS = 64;

    public const SYNC   =  0;
    public const ASYNC  =  1;
    public const NESTED = -1;
    public const SPINS = 1 << 8;

    public static $result;
    public static $stack;
    public static $next;
    public static $nil;
    public static $threadMeta;
    public static $signallers;
    public $xid;
    public $pid;

    private static $initialized = false;

    /**
     * @param port default port for inter process communication via sockets
     */
    public function __construct($r = null, ?int $port = 1081, ?int $maxTasks = self::DEFAULT_MAX_DEPENDENT_TASKS, ?int $maxTaskSize = self::DEFAULT_SIZE)
    {
        $this->xid = md5(microtime() . rand());
        $this->pid = getmypid();

        if (self::$initialized === false) { 
            LockSupport::init($port);

            $notification = new ReentrantLockNotification(true);
            ForkJoinTask::init($notification);

            self::$nil = new AltResult(null);
            //@ATTENTION - need a table to store results for different completable futures
            $result = new \Swoole\Table($maxTasks);
            $result->column('result', \Swoole\Table::TYPE_STRING, $maxTaskSize);
            $result->column('xid', \Swoole\Table::TYPE_INT, 8);          
            $result->create();
            self::$result = $result;

            $stack = new \Swoole\Table($maxTasks);
            $stack->column('xid', \Swoole\Table::TYPE_INT, 8);
            $stack->column('task', \Swoole\Table::TYPE_STRING, $maxTaskSize);
            $stack->create();

            self::$stack = $stack;

            $next = new \Swoole\Table($maxTasks);
            $next->column('xid', \Swoole\Table::TYPE_INT, 8);
            $next->column('task', \Swoole\Table::TYPE_STRING, $maxTaskSize);
            $next->create();

            self::$next = $next;

            $meta = new \Swoole\Table(128);
            $meta->column('pid', \Swoole\Table::TYPE_INT);
            $meta->column('probe', \Swoole\Table::TYPE_INT);
            $meta->column('seed', \Swoole\Table::TYPE_INT);
            $meta->column('secondary', \Swoole\Table::TYPE_INT);
            $meta->create();

            self::$threadMeta = $meta;

            self::$signallers = new \Swoole\Table(128);
            self::$signallers->column('pid', \Swoole\Table::TYPE_INT, 8);
            self::$signallers->create();

            self::$initialized = true;
        }
        if ($r !== null) {
            self::$result->set($this->xid, ['result' => $r]);
        }
    }

    public function getXid(): string
    {
        return $this->xid;
    }

    public function __serialize(): array
    {
        return [
            'xid' => $this->xid,
            'pid' => $this->pid
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->xid = $data['xid'];
        $this->pid = $data['pid'];
    }

    public function internalComplete($r): bool
    {
        if (self::$result->get($this->xid) === false) {
            self::$result->set($this->xid, ['xid' => $this->xid, 'result' => $r]);
            return true;
        }
        return false;
    }

    /** Returns true if successfully pushed c onto stack. */
    public function tryPushStack(Completion $c): bool
    {
        $h = self::$stack->get($this->xid, 'task');        
        if ($h === false) {
            self::$next->del($c->getXid());
        } else {
            $hu = unserialize($h);
            self::$next->set($c->getXid(), ['xid' => $hu->getXid(), 'task' => $h]);
        }
        if ($h == self::$stack->get($this->xid, 'task')) {
            self::$stack->set($this->xid, ['xid' => $c->getXid(), 'task' =>serialize($c)]);
            return true;
        }
        return false;
    }

    public function pushStack(Completion $c): void
    {
        $this->tryPushStack($c);
    }

    /** Completes with the null value, unless already completed. */
    public function completeNull(): bool
    {
        if (self::$result->get($this->xid) === false) {
            self::$result->set($this->xid, ['result' => serialize(self::$nil)]);
            return true;
        }
        return false;
    }

    /** Returns the encoding of the given non-exceptional value. */
    public function encodeValue($t = null)
    {
        return ($t === null) ? self::$nil : $t;
    }

    /** Completes with a non-exceptional result, unless already completed. */
    public function completeValue($t = null): bool
    {
        if (self::$result->get($this->xid) === false) {
            if ($t === null) {
                self::$result->set($this->xid, ['result' => serialize(self::$nil)]);
            } else {
                self::$result->set($this->xid, ['result' => $t]);
            }
            return true;
        }
        return false;
    }

    /**
     * Returns the encoding of the given (non-null) exception as a
     * wrapped CompletionException unless it is one already.
     */
    public static function encodeThrowable(\Throwable $x, $r = null): AltResult
    {
        if ($r == null) {
            return new AltResult(($x instanceof CompletionException) ? $x :
                             new CompletionException($x));
        } else {
            if (!($x instanceof CompletionException)) {
                $x = new CompletionException($x);
            } elseif ($r instanceof AltResult && $x == $r->ex) {
                return $r;
            }
            return new AltResult($x);
        }
    }

    /** Completes with an exceptional result, unless already completed. */
    public function completeThrowable(\Throwable $x, $r = null): bool
    {
        if (self::$result->get($this->xid) === false) {
            self::$result->set($this->xid, ['result' => serialize(self::encodeThrowable($x, $r))]);
            return true;
        }
        return false;
    }

    /**
     * Returns the encoding of the given arguments: if the exception
     * is non-null, encodes as AltResult.  Otherwise uses the given
     * value, boxed as NIL if null.
     */
    public function encodeOutcome($t = null, ?\Throwable $x = null)
    {
        return ($x == null ? ($t == null ? self::$nil : $t) : self::encodeThrowable($x));
    }

    /**
     * Returns the encoding of a copied outcome; if exceptional,
     * rewraps as a CompletionException, else returns argument.
     */
    public static function encodeRelay($r)
    {
        $x = null;
        if ($r instanceof AltResult
            && ($x = $r->ex) !== null
            && !($x instanceof CompletionException)) {
            $r = serilize(new AltResult(new CompletionException($x)));
        }
        return $r;
    }

    /**
     * Completes with r or a copy of r, unless already completed.
     * If exceptional, r is first coerced to a CompletionException.
     */
    public function completeRelay($r): bool
    {
        if (self::$result->get($this->xid) === false) {
            self::$result->set($this->xid, ['result' => self::encodeRelay($r)]);
            return true;
        }
        return false;
    }

    /**
     * Reports result using Future.get conventions.
     */
    private static function reportGet($r = null)
    {
        if ($r == null) {// by convention below, null means interrupted
            throw new \Exception("Interrupted");
        }
        if ($r instanceof AltResult) {
            $x = null;
            $cause = null;
            if (($x = $r->ex) === null) {
                return null;
            }
            if ($x instanceof CancellationException) {
                throw $x;
            }
            throw new \Exception("Execution exeption");
        }
        return $r;
    }

    /**
     * Decodes outcome to return result or throw unchecked exception.
     */
    private static function reportJoin($r = null)
    {
        if ($r instanceof AltResult) {
            $x = null;
            if (($x = $r->ex) === null) {
                return null;
            }
            if ($x instanceof CancellationException || $x instanceof CompletionException){
                throw $x;
            }
            throw new CompletionException(/*$x*/);
        }
        return $r;
    }

    public static function screenExecutor(?ExecutorInterface $e): ExecutorInterface
    {
        if ($e === null) {
            throw new \Exception("Executor must not be null");
        }
        return $e;
    }

    /**
     * Pops and tries to trigger all reachable dependents.  Call only
     * when known to be done.
     */
    public function postComplete(): void
    {
        /*
         * On each step, variable f holds current dependents to pop
         * and run.  It is extended along only one path at a time,
         * pushing others to avoid unbounded recursion.
         */
        $f = $this;
        $h = null;
        while (($h = self::$stack->get($f->getXid(), 'task')) !== false ||
               ($f !== $this && ($h = self::$stack->get(($f = $this)->getXid(), 'task')) !== false)) {
            $d = null;
            $t = null;
            $hu = unserialize($h);
            if ($h == self::$stack->get($f->getXid(), 'task')) {
                $n = self::$next->get($hu->getXid(), 'task');
                if ($n === false) {
                    self::$stack->del($f->getXid());
                } else {
                    $nu = unserialize($n);
                    self::$stack->set($f->getXid(), ['xid' => $nu->getXid(), 'task' => $n]);
                    if ($f !== $this) {
                        $this->pushStack($hu);
                        continue;
                    }
                    if ($n == self::$next->get($hu->getXid(), 'task')) {
                        self::$next->del($hu->getXid());
                    }
                }
                $f = ($d = $hu->tryFire(self::NESTED)) === null ? $this : $d;
            }
        }
    }

    /** Traverses stack and unlinks one or more dead Completions, if found. */
    public function cleanStack(): void
    {
        $p = self::$stack->get($this->getXid(), 'task');
        $pu = unserialize($p);
        // ensure head of stack live
        for ($unlinked = false;;) {
            if ($p === false) {
                return;
            } elseif ($pu->isLive()) {
                if ($unlinked) {
                    return;
                } else {
                    break;
                }
            } elseif ($p == self::$stack->get($this->getXid(), 'task')) {
                $pu = unserialize($p);
                $n = self::$next->get($pu->getXid(), 'task');
                if ($n === false) {
                    self::$stack->del($this->getXid());
                } else {
                    $nu = unserialize($n);
                    self::$stack->set($this->getXid(), ['xid' => $nu->getXid(), 'task' => $n]);
                }
                $unlinked = true;
            } else {
                $p = self::$stack->get($this->getXid(), 'task');
                $pu = unserialize($p);
            }
        }
        // try to unlink first non-live
        for ($q = self::$next->get($pu->getXid(), 'task'); $q !== false;) {
            $qu = unserialize($q);
            $s = self::$next->get($qu->getXid(), 'task');
            if ($qu->isLive()) {
                $p = $q;
                $q = $s;
            } elseif ($q == self::$next->get($pu->getXid(), 'task')) {
                $su = unserialize($s);
                self::$next->set($pu->getXid(), ['xid' => $su->getXid(), 'task' => $s]);
                break;
            } else {
                $q = self::$next->get($pu->getXid(), 'task');
            }
        }
    }

    /**
     * Pushes the given completion unless it completes while trying.
     * Caller should first check that result is null.
     */
    public function unipush(?Completion $c): void
    {
        if ($c !== null) {
            while (!$this->tryPushStack($c)) {
                if (self::$result->get($this->xid) !== false) {
                    self::$next->del($c->getXid());
                    break;
                }              
            }
            if (self::$result->get($this->xid) !== false) {
                $c->tryFire(self::SYNC);
            }
        }
    }

    public function bipush(CompletableFuture $b, ?BiCompletion $c): void
    {
        if ($c !== null) {
            while (self::$result->get($this->xid) === false) {
                if ($this->tryPushStack($c)) {
                    if (self::$result->get($b->getXid()) === false) {
                        $b->unipush(new CoCompletion($c));
                    } elseif (self::$result->get($this->xid) !== false) {
                        $c->tryFire(self::SYNC);
                    }
                    return;
                }
            }
            $b->unipush($c);
        }
    }

    public function biApply($r, $s, callable $f, ?BiApply $c): bool
    {
        $x = null;
        if (self::$result->get($this->xid) === false) {
            if ($r instanceof AltResult) {
                if (($x = $r->ex) !== null) {
                    $this->completeThrowable($x, $r);
                    return true;
                }
                $r = null;
            }
            if ($s instanceof AltResult) {
                if (($x = $s->ex) !== null) {
                    $this->completeThrowable($x, $s);
                    return true;
                }
                $s = null;
            }
            try {
                if ($c !== null && !$c->claim()) {
                    return false;
                }
                $this->completeValue($f($r, $s));
            } catch (\Throwable $ex) {
                $this->completeThrowable($ex);
            }
        }
        return true;
    }

    private function biApplyStage(?ExecutorInterface $e, /*CompletionStage*/$o, callable $f): ?CompletableFuture
    {
        if (($b = $o->toCompletableFuture()) === null) {
            throw new \Exception("NullPointer");
        }
        $d = $this->newIncompleteFuture();
        if (($r = self::$result->get($this->xid)) === false || ($s = self::$result->get($b->getXid())) === false) {
            $this->bipush($b, new BiApply($e, $d, $this, $b, $f));
        } elseif ($e === null) {
            $d->biApply($r['result'], $s['result'], $f, null);
        } else {
            try {
                $e->execute(new BiApply(null, $d, $this, $b, $f));
            } catch (\Throwable $ex) {
                self::$result->set($d->getXid(), ['result' => self::encodeThrowable($ex)]);
            }
        }
        return $d;
    }

    public function biRun($r, $s, RunnableInterface | callable $f, ?BiRun $c): bool
    {
        $x = null;
        $z = null;
        if (self::$result->get($this->xid) === false) {
            if (($r instanceof AltResult
                 && ($x = ($z = $r)->ex) !== null) ||
                ($s instanceof AltResult && ($x = ($z = $s)->ex) !== null)) {
                $this->completeThrowable($x, $z);
            } else {
                try {
                    if ($c !== null && !$c->claim()) {
                        return false;
                    }
                    if ($f instanceof RunnableInterface) {
                        $f->run();
                    } elseif (is_callable($f)) {
                        $f();
                    }
                    $this->completeNull();
                } catch (\Throwable $ex) {
                    $this->completeThrowable($ex);
                }
            }
        }
        return true;
    }

    private function biRunStage(?ExecutorInterface $e, $o, RunnableInterface | callable $f): CompletableFuture
    {
        if ($f == null || ($b = $o->toCompletableFuture()) === null) {
            throw new \Exception("NullPointer");
        }
        $d = $this->newIncompleteFuture();
        if (($r = self::$result->get($this->xid)) === false || ($s = self::$result->get($b->getXid())) === false) {
            $this->bipush($b, new BiRun($e, $d, $this, $b, $f));
        } elseif ($e === null) {
            $d->biRun($r, $s, $f, null);
        } else {
            try {
                $e->execute(new BiRun(null, $d, $this, $b, $f));
            } catch (\Throwable $ex) {
                self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable($ex))]);
            }
        }
        return $d;
    }

    private static function uRShift(int $a, int $b): int
    {
        if ($b == 0) {
            return $a;
        }
        return ($a >> $b) & ~(1<<(8*PHP_INT_SIZE-1)>>($b-1));
    }

    public static function andTree(array $cfs, int $lo, int $hi): ?CompletableFuture
    {
        $d = new CompletableFuture();
        if ($lo > $hi) {// empty
            self::$result->set($d->getXid(), ['result' => serialize(self::$nil)]);
        } else {
            $mid = self::uRShift($lo + $hi, 1);
            if (($a = ($lo == $mid ? (isset($cfs[$lo]) ? $cfs[$lo] : null) :
                      self::andTree($cfs, $lo, $mid))) === null ||
                ($b = (($lo == $hi) ? $a : (($hi == $mid+1) ? (isset($cfs[$hi]) ? $cfs[$hi] : null) : self::andTree($cfs, $mid+1, $hi)))) === null) {
                throw new \Exception("NullPointer");
            }
            if (($r = self::$result->get($a->getXid())) === false || ($s = self::$result->get($b->getXid())) === false) {
                $a->bipush($b, new BiRelay($d, $a, $b));
            } elseif (($r instanceof AltResult
                      && ($x = ($z = $r)->ex) !== null) ||
                     ($s instanceof AltResult
                      && ($x = ($z = $s)->ex) !== null)) {
                self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable($x, $z))]);
            } else {
                self::$result->set($d->getXid(), ['result' => serialize(self::$nil)]);
            }
        }
        return $d;
    }

    /**
     * Pushes completion to this and b unless either done.
     * Caller should first check that result and b.result are both null.
     */
    public function orpush(CompletableFuture $b, ?BiCompletion $c): void
    {
        if ($c !== null) {
            while (!$this->tryPushStack($c)) {
                if (self::$result->get($this->getXid()) !== false) {
                    self::$next->del($c->getXid());
                    break;
                }
            }
            if (self::$result->get($this->getXid()) !== false) {
                $c->tryFire(self::SYNC);
            } else {
                $b->unipush(new CoCompletion($c));
            }
        }
    }

    private function orApplyStage(ExecutorInterface $e, $o, ?callable $f): ?CompletableFuture
    {
        if ($f == null || ($b = $o->toCompletableFuture()) === null) {
            throw new \Exception("NullPointer");
        }

        if (($r = self::$result->get(($z = $this)->getXid())) !== false ||
            ($r = self::$result->get(($z = $b)->getXid())) !== false) {
            return $z->uniApplyNow($r['result'], $e, $f);
        }

        $d = $this->newIncompleteFuture();
        $this->orpush($b, new OrApply($e, $d, $this, $b, $f));
        return $d;
    }

    private function orRunStage(ExecutorInterface $e, $o, RunnableInterface | callable $f): ?CompletableFuture
    {
        if (($b = $o->toCompletableFuture()) === null) {
            throw new \Exception("NullPointer");
        }

        if (($r = self::$result->get(($z = $this)->getXid())) !== false ||
            ($r = self::$result->get(($z = $b)->getXid())) !== false) {
            return $z->uniRunNow($r['result'], $e, $f);
        }

        $d = $this->newIncompleteFuture();
        $this->orpush($b, new OrRun($e, $d, $this, $b, $f));
        return $d;
    }
    
    /**
     * Post-processing by dependent after successful UniCompletion tryFire.
     * Tries to clean stack of source a, and then either runs postComplete
     * or returns this to caller, depending on mode.
     */
    public function postFire(?CompletableFuture $a, CompletableFuture | int $bOrMode = null, ?int $mode = null): ?CompletableFuture
    {
        if (is_int($bOrMode)) {
            $mode = $bOrMode;
            if ($a !== null && self::$stack->get($a->getXid()) !== false) {
                $r = null;
                $res = self::$result->get($a->getXid());
                if ($res === false) {
                    $a->cleanStack();
                }
                if ($mode >= 0 && $res !== false) {
                    $a->postComplete();
                }
            }
            if (self::$result->get($this->getXid()) !== false && self::$stack->get($this->getXid()) !== false) {
                if ($mode < 0) {
                    return $this;
                } else {
                    $this->postComplete();
                }
            }
            return null;
        } else {
            $b = $bOrMode;
            if ($b !== null && self::$stack->get($b->getXid()) !== false) { // clean second source
                if (($r = self::$result->get($b->getXid())) === false) {
                    $b->cleanStack();
                }
                if ($mode >= 0 && ($r !== false || self::$result->get($b->getXid()) !== false)) {
                    $b->postComplete();
                }
            }
            return $this->postFire($a, $mode);
        }
    }

    public function newIncompleteFuture(): CompletableFuture
    {
        return new CompletableFuture();
    }

    private static $defaultExecutorInitialized = false;

    private static $defaultExecutor;

    public static function defaultExecutor(): ExecutorInterface
    {
        if (self::$defaultExecutorInitialized === false) {
            self::$defaultExecutorInitialized = true;
            self::$defaultExecutor = new DefaultPoolExecutor(swoole_cpu_num());
        }
        return self::$defaultExecutor;
    }

    private function uniApplyStage(?ExecutorInterface $e, callable $f): ?CompletableFuture
    {
        $r = null;
        if (($res = self::$result->get($this->getXid())) !== false) {
            $r = $res['result'];
            return $this->uniApplyNow($r, $e, $f);
        }
        $d = $this->newIncompleteFuture();
        $this->unipush(new UniApply($e, $d, $this, $f));
        return $d;
    }

    private function uniApplyNow($r, ?ExecutorInterface $e, callable $f): ?CompletableFuture
    {
        $x = null;
        $d = $this->newIncompleteFuture();
        if ($r instanceof AltResult) {
            if (($x = $r->ex) !== null) {
                self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable($x, $r))]);
                return $d;
            }
            $r = null;
        }
        try {
            if ($e !== null) {
                $e->execute(new UniApply(null, $d, $this, $f));
            } else {
                self::$result->set($d->getXid(), ['result' => serialize($d->encodeValue($f($t))) ]);
            }
        } catch (\Throwable $ex) {
            self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable($ex))]);
        }
        return $d;
    }   

    private function uniRunStage(ExecutorInterface $e, RunnableInterface | callable $f): ?CompletableFuture
    {
        $r = null;
        if (($res = self::$result->get($this->xid)) !== false) {
            return $this->uniRunNow($res['result'], $e, $f);
        }
        $d = $this->newIncompleteFuture();
        $this->unipush(new UniRun($e, $d, $this, $f));
        return $d;
    }

    private function uniRunNow($r, ?ExecutorInterface $e, RunnableInterface | callable $f): ?CompletableFuture
    {
        $x = null;
        $d = $this->newIncompleteFuture();
        if ($r instanceof AltResult && ($x = $r->ex) !== null) {
            self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable( $x, $r )) ]);
        } else {
            try {
                if ($e !== null) {
                    $e->execute(new UniRun(null, $d, $this, $f));
                } else {
                    $f->run();
                    self::$result->set($d->getXid(), ['result' => serialize(self::$nil) ]);
                }
            } catch (\Throwable $ex) {
                self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable( $ex )) ]);
            }
        }
        return $d;
    }

    public function uniWhenComplete($r, $f, ?UniWhenComplete $c): bool
    {
        if (self::$result->get($this->getXid()) === false) {
            try {
                if ($c !== null && !$c->claim()) {
                    return false;
                }
                if ($r instanceof AltResult) {
                    $x = $r->ex;
                    $t = null;
                } else {
                    $tr = $r;
                    $t = $tr;
                }
                $f($t, $x);
                if ($x === null) {
                    $this->internalComplete($r);
                    return true;
                }
            } catch (\Throwable $ex) {
                /*if ($x === null) {
                    $x = $ex;
                } elseif ($x !== $ex) {
                    $x->addSuppressed(ex);
                }*/
                $x = $ex;
            }
            $this->completeThrowable($x, $r);
        }
        return true;
    }

    private function uniWhenCompleteStage(?ExecutorInterface $e, $f): ?CompletableFuture
    {
        $d = $this->newIncompleteFuture();
        if (($r = self::$result->get($this->getXid())) === false) {
            $this->unipush(new UniWhenComplete($e, $d, $this, $f));
        } elseif ($e === null) {
            $d->uniWhenComplete($r['result'], $f, null);
        } else {
            try {
                $e->execute(new UniWhenComplete(null, $d, $this, $f));
            } catch (\Throwable $ex) {
                self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable( $ex )) ]);
            }
        }
        return $d;
    }

    private static function uniCopyStage(CompletableFuture $src): CompletableFuture
    {
        $d = $src->newIncompleteFuture();
        if (($r = self::$result->get($src->getXid())) !== false) {
            self::$result->set($d->getXid(), ['result' => self::encodeRelay($r['result']) ]);
        } else {
            $src->unipush(new UniRelay($d, $src));
        }
        return $d;
    }

    private function uniComposeStage(?ExecutorInterface $e, callable $f): ?CompletableFuture
    {
        $d = $this->newIncompleteFuture();
        if (($r = self::$result->get($this->xid)) === false) {
            $this->unipush(new UniCompose($e, $d, $this, $f));
        } else {
            if ($r instanceof AltResult) {
                if (($x = $r)->ex !== null) {
                    self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable($x, $r['result'])) ]);
                    return $d;
                }
                $r = null;
            }
            try {
                if ($e !== null) {
                    $e->execute(new UniCompose(null, $d, $this, $f));
                } else {
                    $g = ($f($t))->toCompletableFuture();
                    if (($s = self::$result->get($g->getXid())) !== false) {
                        self::$result->set($d->getXid(), ['result' => self::encodeRelay($s) ]);
                    } else {
                        $g->unipush(new UniRelay($d, $g));
                    }
                }
            } catch (\Throwable $ex) {
                self::$result->set($d->getXid(), ['result' => serialize(self::encodeThrowable($ex)) ]);
            }
        }
        return $d;
    }

    public static function asyncSupplyStage(ExecutorInterface $e, $f): ?CompletableFuture
    {
        $d = new CompletableFuture();
        $e->execute(new AsyncSupply($d, $f));
        return $d;
    }

    public static function asyncRunStage(ExecutorInterface $e, RunnableInterface | callable $f): ?CompletableFuture
    {
        $d = new CompletableFuture();
        $e->execute(new AsyncRun($d, $f));
        return $d;
    }

    private function waitingGet(bool $interruptible = false)
    {
        $q = null;
        $queued = false;
        $spins = -1;
        $res = null;
        while (($res = self::$result->get($this->xid)) === false) {
            if ($spins < 0) {
                $spins = self::SPINS;
            } elseif ($spins > 0) {
                if (($nxt = ThreadLocalRandom::nextSecondarySeed(self::$threadMeta)) >= 0) {
                    $spins -= 1;
                }
            } elseif ($q == null) {
                $q = new Signaller($interruptible, 0, 0);
            } elseif (!$queued) {
                $queued = $this->tryPushStack($q);
            } elseif ($interruptible && $q->interrupted) {
                $q->thread = null;
                $this->cleanStack();
                return null;
            } else { //if ($q->thread !== null && self::$result->get($this->xid) === false)
                try {
                    ForkJoinPool::managedBlock($q);
                } catch (\Throwable $ie) {
                    $q->interrupted = true;
                }
            }
        }
        if ($q != null) {
            $q->thread = null;
            if ($q->interrupted) {
                if ($interruptible) {
                    $res = null; // report interruption
                } else {
                    //Thread.currentThread().interrupt();
                    exit(0);
                }
            }
        }
        $this->postComplete();
        return $res === null ? null : $res;
    }

    /**
     * Returns raw result after waiting, or null if interrupted, or
     * throws TimeoutException on timeout.
     */
    private function timedGet(int $nanos)
    {
        //if (Thread.interrupted())
        //    return null;
        if ($nanos <= 0) {
            throw new \Exception("Timeout");
        }
        $d = hrtime(true) + $nanos;
        $q = new Signaller(true, $nanos, $d == 0 ? 1 : d); // avoid 0
        $queued = false;
        $res = null;
        // We intentionally don't spin here (as waitingGet does) because
        // the call to nanoTime() above acts much like a spin.
        while (($res = self::$result->get($this->xid)) === false) {
            if (!$queued) {
                $queued = $this->tryPushStack($q);
            } elseif ($q->interruptControl < 0 || $q->nanos <= 0) {
                $q->thread = null;
                $this->cleanStack();
                if ($q->interruptControl < 0) {
                    return null;
                }
                throw new \Exception("Timeout");
            } elseif ($q->thread !== null && self::$result->get($this->xid) === false) {
                try {
                    ForkJoinPool::managedBlock($q);
                } catch (\Throwable $ie) {
                    $q->interruptControl = -1;
                }
            }
        }
        if ($q->interruptControl < 0) {
            $res = null;
        }
        $q->thread = null;
        $this->postComplete();
        return $res === null ? null : $res;
    }

    /**
     * Returns a new CompletableFuture that is asynchronously completed
     * by a task running in the given executor with the value obtained
     * by calling the given Supplier.
     *
     * @param supplier a function returning the value to be used
     * to complete the returned CompletableFuture
     * @param executor the executor to use for asynchronous execution
     * @param <U> the function's return type
     * @return the new CompletableFuture
     */
    public static function supplyAsync($supplier, ?ExecutorInterface $executor = null): CompletableFuture
    {
        $d = self::asyncSupplyStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $supplier);
        return $d;
    }

    /**
     * Returns a new CompletableFuture that is asynchronously completed
     * by a task running in the given executor after it runs the given
     * action.
     *
     * @param runnable the action to run before completing the
     * returned CompletableFuture
     * @param executor the executor to use for asynchronous execution
     * @return the new CompletableFuture
     */
    public static function runAsync(RunnableInterface | callable $runnable, ?ExecutorInterface $executor = null): CompletableFuture
    {
        return self::asyncRunStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $runnable);
    }

    /**
     * Returns a new CompletableFuture that is already completed with
     * the given value.
     *
     * @param value the value
     * @param <U> the type of the value
     * @return the completed CompletableFuture
     */
    public static function completedFuture($value): CompletableFuture
    {
        return new CompletableFuture(($value == null) ? self::$nil : $value);
    }

    /**
     * Returns {@code true} if completed in any fashion: normally,
     * exceptionally, or via cancellation.
     *
     * @return {@code true} if completed
     */
    public function isDone(): bool
    {
        return self::$result->get($this->xid) !== false;
    }

    /**
     * Waits if necessary for at most the given time for this future
     * to complete, and then returns its result, if available.
     *
     * @param timeout the maximum time to wait
     * @param unit the time unit of the timeout argument
     * @return the result value
     * @throws CancellationException if this future was cancelled
     * @throws ExecutionException if this future completed exceptionally
     * @throws InterruptedException if the current thread was interrupted
     * while waiting
     * @throws Exception if the wait timed out
     */
    public function get(?int $timeout = null, ?string $unit = null)
    {
        if ($timeout !== null && $unit !== null) {
            $nanos = TimeUnit::toNanos($timeout, $unit);
            $res = null;
            if (($res = self::$result->get($this->xid)) === false) {
                $res = $this->timedGet($nanos);
            }
        } else {
            //$res = null;
            if (($res = self::$result->get($this->xid)) === false) {
                $res = $this->waitingGet();
            }
        }
        return $this->reportGet($res === false ? null : $res['result']);
    }

    /**
     * Returns the result value when complete, or throws an
     * (unchecked) exception if completed exceptionally. To better
     * conform with the use of common functional forms, if a
     * computation involved in the completion of this
     * CompletableFuture threw an exception, this method throws an
     * (unchecked) {@link CompletionException} with the underlying
     * exception as its cause.
     *
     * @return the result value
     * @throws CancellationException if the computation was cancelled
     * @throws CompletionException if this future completed
     * exceptionally or a completion computation threw an exception
     */
    public function join()
    {
        $r = null;
        if (($r = self::$result->get($this->xid)) === false) {
            $r = $this->waitingGet(false);
        }
        return $this->reportJoin($r === null ? null : $r['result']);
    }

    /**
     * Returns the result value (or throws any encountered exception)
     * if completed, else returns the given valueIfAbsent.
     *
     * @param valueIfAbsent the value to return if not completed
     * @return the result value, if completed, else the given valueIfAbsent
     * @throws CancellationException if the computation was cancelled
     * @throws CompletionException if this future completed
     * exceptionally or a completion computation threw an exception
     */
    public function getNow($valueIfAbsent)
    {
        $r = null;
        return (($r = self::$result->get($this->xid)) === false) ? $valueIfAbsent : $this->reportJoin($r['result']);
    }

    public function resultNow()
    {
        $res = self::$result->get($this->xid);
        if ($res !== false) {
            $r = $res['result'];
            if ($r instanceof AltResult) {
                if ($r->ex == null) {
                    return null;
                }
            } else {
                return $r;
            }
        }
        throw new \Exception("IllegalState");
    }

    /**
     * If not already completed, sets the value returned by {@link
     * #get()} and related methods to the given value.
     *
     * @param value the result value
     * @return {@code true} if this invocation caused this CompletableFuture
     * to transition to a completed state, else {@code false}
     */
    public function complete($value): bool
    {
        $triggered = $this->completeValue($value);
        $this->postComplete();
        return $triggered;
    }

    public function thenApply(callable $fn): CompletionStageInterface
    {
        return $this->uniApplyStage(null, $fn);
    }

    public function thenApplyAsync(callable $fn, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->uniApplyStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $fn);
    }

    public function thenRun(RunnableInterface | callable $action): CompletionStageInterface
    {
        return $this->uniRunStage(null, $action);
    }

    public function thenRunAsync(RunnableInterface | callable $action, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->uniRunStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $action);
    }

    public function thenCombine(CompletionStageInterface $other, $fn): CompletionStageInterface
    {
        return $this->biApplyStage(null, $other, $fn);
    }

    public function thenCombineAsync(CompletionStageInterface $other, $fn, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->biApplyStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $other, $fn);
    }

    public function runAfterBoth(CompletionStageInterface $other, RunnableInterface | callable $action): CompletionStageInterface
    {
        return $this->biRunStage(null, $other, $action);
    }

    public function runAfterBothAsync(CompletionStageInterface $other, RunnableInterface | callable $action, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->biRunStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $other, $action);
    }

    public function applyToEither(CompletionStageInterface $other, callable $fn): CompletionStageInterface
    {
        return $this->orApplyStage(null, $other, $fn);
    }

    public function applyToEitherAsync(CompletionStageInterface $other, callable $fn, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->orApplyStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $other, $fn);
    }

    public function runAfterEither(CompletionStageInterface $other, RunnableInterface | callable $action): CompletionStageInterface
    {
        return $this->orRunStage(null, $other, $action);
    }

    public function runAfterEitherAsync(CompletionStageInterface $other, RunnableInterface | callable $action, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->orRunStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $other, $action);
    }

    public function thenCompose(callable $fn): CompletionStageInterface
    {
        return $this->uniComposeStage(null, $fn);
    }

    public function thenComposeAsync(callable $fn, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->uniComposeStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $fn);
    }

    public function whenComplete($action): CompletionStageInterface
    {
        return $this->uniWhenCompleteStage(null, $action);
    }

    public function whenCompleteAsync($action, ?ExecutorInterface $executor = null): CompletionStageInterface
    {
        return $this->uniWhenCompleteStage($executor == null ? self::defaultExecutor() : self::screenExecutor($executor), $action);
    }

    /**
     * Returns a new CompletableFuture that is completed when all of
     * the given CompletableFutures complete.  If any of the given
     * CompletableFutures complete exceptionally, then the returned
     * CompletableFuture also does so, with a CompletionException
     * holding this exception as its cause.  Otherwise, the results,
     * if any, of the given CompletableFutures are not reflected in
     * the returned CompletableFuture, but may be obtained by
     * inspecting them individually. If no CompletableFutures are
     * provided, returns a CompletableFuture completed with the value
     * {@code null}.
     *
     * <p>Among the applications of this method is to await completion
     * of a set of independent CompletableFutures before continuing a
     * program, as in: {@code CompletableFuture.allOf(c1, c2,
     * c3).join();}.
     *
     * @param cfs the CompletableFutures
     * @return a new CompletableFuture that is completed when all of the
     * given CompletableFutures complete
     * @throws NullPointerException if the array or any of its elements are
     * {@code null}
     */
    public static function allOf(...$cfs): CompletableFuture
    {
        return self::andTree($cfs, 0, count($cfs) - 1);
    }

    /**
     * Returns a new CompletableFuture that is completed when any of
     * the given CompletableFutures complete, with the same result.
     * Otherwise, if it completed exceptionally, the returned
     * CompletableFuture also does so, with a CompletionException
     * holding this exception as its cause.  If no CompletableFutures
     * are provided, returns an incomplete CompletableFuture.
     *
     * @param cfs the CompletableFutures
     * @return a new CompletableFuture that is completed with the
     * result or exception of any of the given CompletableFutures when
     * one completes
     * @throws NullPointerException if the array or any of its elements are
     * {@code null}
     */
    public static function anyOf(...$cfs): CompletableFuture
    {
        if (($n = count($cfs)) <= 1) {
            return ($n == 0)
                ? new CompletableFuture()
                : $this->uniCopyStage($cfs[0]);
        }
        for ($i = 0; $i < count($cfs); $i += 1) {
            $cf = $cfs[$i];
            if (($r = self::$result->get($cf->getXid())) !== false) {
                return new CompletableFuture(self::encodeRelay($r['result']));
            }
        }
        $d = new CompletableFuture();
        for ($i = 0; $i < count($cfs); $i += 1) {
            $cf = $cfs[$i];
            $cf->unipush(new AnyOf($d, $cf, $cfs));
        }
        // If d was completed while we were adding completions, we should
        // clean the stack of any sources that may have had completions
        // pushed on their stack after d was completed.
        if (self::$result->get($d->getXid()) !== false) {
            for ($i = 0, $len = count($cfs); $i < $len; $i += 1) {
                if (self::$result->get($cfs[$i]->getXid()) !== false) {
                    for ($i += 1; $i < $len; $i += 1) {
                        if (self::$result->get($cfs[$i]->getXid()) === false) {
                            $cfs[$i]->cleanStack();
                        }
                    }
                }
            }
        }
        return $d;
    }

    /**
     * Completes this CompletableFuture with the result of
     * the given Supplier function invoked from an asynchronous
     * task using the given executor.
     *
     * @param supplier a function returning the value to be used
     * to complete this CompletableFuture
     * @param executor the executor to use for asynchronous execution
     * @return this CompletableFuture
     */
    public function completeAsync($supplier, ?ExecutorInterface $executor = null): CompletableFuture
    {
        $executor = ($executor == null) ? self::defaultExecutor() : self::screenExecutor($executor);
        $executor->execute(new AsyncSupply($this, $supplier));
        return $this;
    }

    public function toCompletableFuture(): CompletableFuture
    {
        return $this;
    }
}
