<?php

namespace Tests;

/*error_reporting(E_ALL & ~E_NOTICE);

set_error_handler(function($severity, $message, $file, $line) {
    fwrite(STDERR, getmypid() . ": error $message in $file at $line\n");
});*/

use PHPUnit\Framework\TestCase;
use Concurrent\{
    RunnableInterface,
    ThreadInterface
};
use Concurrent\Queue\ForkJoinWorkQueue;
use Concurrent\Executor\{
    ForkJoinPool,
    DefaultPoolExecutor
};
use Concurrent\Lock\LockSupport;
use Concurrent\Task\CompletableFuture;

class CompletableFutureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        LockSupport::init(1085);
    }

    public function testSupplyThenApplyAsync(): void
    {
        $executor = new DefaultPoolExecutor(4);
        $inputValue = 4;
        
        $future = CompletableFuture::supplyAsync(function () use ($inputValue) {
            $res = $inputValue * $inputValue;
            fwrite(STDERR, getmypid() . ": (1) inputValue * inputValue = " . ($res) . "\n");
            return $res;
        }, $executor)->thenApplyAsync(function ($result) {
            usleep(100000);
            $res = $result * 2;
            fwrite(STDERR, getmypid() . ": (2) result * 2 = " . ($res) . "\n");
            return $res;
        }, $executor)->thenApplyAsync(function ($result) {
            $res = $result * 2;
            fwrite(STDERR, getmypid() . ": (3) result * 2 = " . ($res) . "\n");
            return $res;
        }, $executor);

        $output = $future->get();

        fwrite(STDERR, getmypid() . ": (1) Final result of " . $future->getXid() . ": $output\n");

        $this->assertEquals(64, $output);

        //============================Test ForkJoinPool
        $executor = ForkJoinPool::commonPool();
        $inputValue = 4;
        
        $future = CompletableFuture::supplyAsync(function () use ($inputValue) {
            return $inputValue * $inputValue;
        }, $executor)->thenApplyAsync(function ($result) {
            usleep(100000);
            return $result * 2;
        }, $executor)->thenApply(function ($result) {
            return $result * 2;
        }, $executor);

        $output = $future->get();

        fwrite(STDERR, getmypid() . ": (2) Final result: $output\n");

        $this->assertEquals(64, $output);
    }

    public function testSupplyThenApplyAsyncNoExecutor(): void
    {
        $inputValue = 4;
        $future = CompletableFuture::supplyAsync(function () use ($inputValue) {
            fwrite(STDERR, getmypid() . ": (1) first computation\n");
            return $inputValue * $inputValue;
        })->thenApplyAsync(function ($result) {
            usleep(200000);
            fwrite(STDERR, getmypid() . ": (2) second computation\n");
            return $result * 2;
        })->thenApplyAsync(function ($result) {
            fwrite(STDERR, getmypid() . ": (3) finishing computation\n");
            return $result * 2;
        });

        $output = $future->get();        

        fwrite(STDERR, getmypid() . ": (4) Final result: $output\n");

        $this->assertEquals(64, $output);
        
    }

    public function testSupplyThenRunAsyncNoExecutor(): void
    {
        $inputValue = 4;

        $future = CompletableFuture::supplyAsync(function () use ($inputValue) {
            $res = $inputValue * $inputValue;
            fwrite(STDERR, getmypid() . ": (1) inputValue * inputValue = " . ($res) . "\n");
            return $res;
        })->thenApplyAsync(function ($result) {
            usleep(100000);
            $res = $result * 2;
            fwrite(STDERR, getmypid() . ": (2) result * 2 = " . ($res) . "\n");
            return $res;
        });
        
        $future->thenRunAsync(function () {
            fwrite(STDERR, getmypid() . ": (2) Running a follow-up background task...\n");
        });

        $output = $future->get();    
        
        usleep(200000);

        fwrite(STDERR, getmypid() . ": Final result: $output\n");

        $this->assertEquals(32, $output);
    }

    public function testThenCombineAsync(): void
    {
        $future1 = CompletableFuture::supplyAsync(function () {
            fwrite(STDERR, getmypid() . ": (1) combine async return 2\n");
            return 2;
        });
        $future2 = CompletableFuture::supplyAsync(function () {
            fwrite(STDERR, getmypid() . ": (2) combine async return 3\n");
            return 3;
        });

        $resultFuture = $future1->thenCombineAsync($future2, function ($result1, $result2) {
            fwrite(STDERR, getmypid() . ": (3) then combine async sum $result1, $result2\n");
            return $result1 + $result2;
        });

        $this->assertEquals(5, $resultFuture->join());    
    }

    public function testRunAfterBothAsync(): void
    {
        $future1 = CompletableFuture::runAsync(function () {
            fwrite(STDERR, getmypid() . ": (1) wait 0.1 sec\n");
            // Simulate a task
            usleep(100000);
        });
        $future2 = CompletableFuture::runAsync(function () {
            // Simulate a task
            fwrite(STDERR, getmypid() . ": (2) wait 0.2 sec\n");
            usleep(200000);
        });
    
        $combinedFuture = $future1->runAfterBothAsync($future2, function () {
            fwrite(STDERR, getmypid() . ": (3) executes after both\n");
        });
    
        $combinedFuture->join();

        $this->assertTrue(true);
    }

    public function testApplyToEitherAsync(): void
    {
        $future1 = CompletableFuture::supplyAsync(function () {
            usleep(100000);
            fwrite(STDERR, getmypid() . ": (3) first with waiting completes\n");
            return 2;
        });
        $future2 = CompletableFuture::supplyAsync(function () {
            fwrite(STDERR, getmypid() . ": (1) second completes\n");
            return 1;
        });
    
        $resultFuture = $future1->applyToEitherAsync($future2, function ($result) {
            fwrite(STDERR, getmypid() . ": (2) third completes\n");
            return $result * 2;
        });
    
        // Verify: The operation should return 1 from future2 and double it to 2
        $this->assertEquals(2, $resultFuture->get());
        usleep(200000);
    }

    public function testRunAfterEitherAsync(): void
    {
        $future1 = CompletableFuture::runAsync(function () {
            // Simulate a task that takes longer
            usleep(200000);
            fwrite(STDERR, getmypid() . ": (3) first run goes last\n");
        });
        $future2 = CompletableFuture::runAsync(function () {
            // Simulate a quicker start
            usleep(100000);
            fwrite(STDERR, getmypid() . ": (1) second run goes first\n");
        });
        $combinedFuture = $future1->runAfterEitherAsync($future2, function () {
            fwrite(STDERR, getmypid() . ": (2) logging comes after run with shorter sleep\n");
        });
    
        $combinedFuture->join();
        $this->assertTrue(true);
        usleep(300000);
    }

    public function testWhenCompleteAsync(): void
    {
        $future = CompletableFuture::supplyAsync(function () {
            fwrite(STDERR, getmypid() . ": (1) first computation\n");
            return "Hello";
        })->thenApplyAsync(function ($result) {
            fwrite(STDERR, getmypid() . ": (2) second computation, previous value: $result\n");
            return $result . " World";
        })->whenCompleteAsync(function ($result, $exception) {
            fwrite(STDERR, getmypid() . ": (3) third computation, previous value: $result\n");
            if ($exception == null) {
                assert(strpos($result, "World") !== false);
            }
        });

        $this->assertEquals("Hello World", $future->get());
    }

    public function testAllOf(): void
    {
        $future1 = CompletableFuture::supplyAsync(function () {
            return "Hello";
        });
        $future2 = CompletableFuture::supplyAsync(function () {
            return "World";
        });

        $combinedFuture = CompletableFuture::allOf($future1, $future2);

        $combinedFuture->get(); // Waits for all futures to complete

        $this->assertTrue($future1->isDone());
        $this->assertTrue($future2->isDone());
        $this->assertEquals("Hello", $future1->get());
        $this->assertEquals("World", $future2->get());
    }

    public function testAnyOf(): void
    {
        $future1 = CompletableFuture::supplyAsync(function () {
            usleep(200000);
            fwrite(STDERR, getmypid() . ": (2) first of any returns last\n");
            return "Hello";
        });
        $future2 = CompletableFuture::supplyAsync(function () {
            fwrite(STDERR, getmypid() . ": (1) second of any returns first\n");
            return "World";
        });

        $anyOfFuture = CompletableFuture::anyOf($future1, $future2);

        $result = $anyOfFuture->get();

        usleep(300000);

        $this->assertTrue($result == "World");
    }
}
