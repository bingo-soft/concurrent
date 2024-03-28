[![Latest Stable Version](https://poser.pugx.org/bingo-soft/concurrent/v/stable.png)](https://packagist.org/packages/bingo-soft/concurrent)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/bingo-soft/concurrent/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/bingo-soft/concurrent/?branch=main)

# Concurrent

Concurrent programming constructs for PHP


# Installation

Install library, using Composer:

```
composer require bingo-soft/concurrent
```

# Example 1 (simple pool)

```php
$pool = new DefaultPoolExecutor(3); //only three active processes in the pool
$task1 = new TestTask("task 1");
$task2 = new TestTask("task 2");
$task3 = new TestTask("task 3");
$task4 = new TestTask("task 4");
$task4 = new TestTask("task 5");

$pool->execute($task1);
$pool->execute($task2);
$pool->execute($task3);
$pool->execute($task4); //task for is waiting for an empty slot in the pool
$pool->execute($task5); //task for is waiting for an empty slot in the pool
$pool->shutdown(); //shutdown pool with all processes attached
```

# Example 2 (CountDownLatch)

```php
    //IPC is implemented via server socket on default port 1081? can be changed
    
    $initializer = new ComponentInitializer(4);
        
    // Simulate component initialization
    $initializer->initializeComponent(function () {
        fwrite(STDERR, getmypid() . ": Process is sleeping for 1 second\n");
        sleep(1);
    });
    $initializer->initializeComponent(function () {
        fwrite(STDERR, getmypid() . ": Process is sleeping for 2 seconds\n");
        sleep(2);
    });
    $initializer->initializeComponent(function () {
        fwrite(STDERR, getmypid() . ": Process is sleeping for 3 seconds\n");
        sleep(3);
    });
    $initializer->initializeComponent(function () {
        fwrite(STDERR, getmypid() . ": Process is sleeping for 4 seconds\n");
        sleep(4);
    });      

    $start = hrtime(true);
    $initializer->awaitInitialization();
    $end = hrtime(true);

    //Less than 5 seconds total
    assert(floor(($end - $start) / 1000000) < 5000);
```

# Example 3 (ForkJoinPool with recursive task)

```php

    //SumTask.php with recursive task

    use Concurrent\ThreadInterface;
    use Concurrent\Task\RecursiveTask;

    class SumTask extends RecursiveTask
    {
        public $start;
        public $end;
        private const THRESHOLD = 10000; // Arbitrary threshold to split tasks
        public $timestamp;

        public function __construct(int $start, int $end)
        {
            parent::__construct();
            $this->start = $start;
            $this->end = $end;
            $this->timestamp = hrtime(true);
        }

        public function castResult($result)
        {
            return intval($result);
        }

        public function __serialize(): array
        {
            return [
                'xid' => $this->xid,
                'start' => $this->start,
                'end' => $this->end,
                'timestamp' => $this->timestamp,
                'result' => self::$result->get($this->xid, 'result')
            ];
        }

        public function __unserialize(array $data): void
        {
            $this->xid = $data['xid'];
            $this->start = $data['start'];
            $this->end = $data['end'];
            $this->timestamp = $data['timestamp'];
        }

        public function compute(ThreadInterface $worker, ...$args)
        {
            $length = $this->end - $this->start;
            if ($length <= self::THRESHOLD) { // Base case
                $sum = 0;
                for ($i = $this->start; $i <= $this->end; $i++) {
                    $sum += $i;
                }            
                return $sum;
            } else { // Recursive case
                $mid = $this->start + ($this->end - $this->start) / 2;
                $leftTask = new SumTask($this->start, $mid);
                $rightTask = new SumTask($mid + 1, $this->end);
                $leftTask->fork($worker); // Fork the first task  
                $firstHalf = $rightTask->compute($worker, ...$args);
                $secondHalf = $leftTask->join($worker, ...$args); // Join results
                    return $firstHalf + $secondHalf;
            }
        }
    }

    // ===== Usage

    //Initialize pool, can reset default port of inter process communication
    $pool = ForkJoinPool::commonPool(/*1081*/);

    //Invoke recursive task on pool
    $result = $pool->invoke(new SumTask(1, 300000));
    assert(45000150000, $result); 
```

Warning - ForkJoinPool requires stabilization, need to apply new features from OpenJDK.


# Example 4 (CompletableFuture based on multiprocessing)

```php

    //4.1 
    $inputValue = 4;

    //issue non-blocking calls executed on process pool
    $future = CompletableFuture::supplyAsync(function () use ($inputValue) {
        $res = $inputValue * $inputValue;
        return $res;
    }, $executor)->thenApplyAsync(function ($result) {
        usleep(100000);
        $res = $result * 2;
        return $res;
    }, $executor)->thenApplyAsync(function ($result) {
        $res = $result * 2;
        return $res;
    }, $executor);

    //blocking call to get resulting value
    assert($future->get() == 64);


    //4.2

    $inputValue = 4;

    $future = CompletableFuture::supplyAsync(function () use ($inputValue) {
        $res = $inputValue * $inputValue;
        return $res;
    })->thenApplyAsync(function ($result) {
        usleep(100000);
        $res = $result * 2;
        return $res;
    });
    
    //non-blocking call that runs after first two non-blocking calls - can be used for logging etc.
    $future->thenRunAsync(function () {
        fwrite(STDERR, "Running a follow-up background task...\n");
    });

    assert($future->get() == 32);

    //4.3

    $future1 = CompletableFuture::supplyAsync(function () {
        return 2;
    });
    $future2 = CompletableFuture::supplyAsync(function () {
        return 3;
    });

    //combine results of two futures and make non-blocking computation
    $resultFuture = $future1->thenCombineAsync($future2, function ($result1, $result2) {
        return $result1 + $result2;
    });

    assert($resultFuture->join() == 5);

    //4.4

    $future1 = CompletableFuture::runAsync(function () {
        // Simulate a task
        usleep(100000);
    });
    $future2 = CompletableFuture::runAsync(function () {
        // Simulate a task
        usleep(200000);
    });

    //non-blocking task running after both futures complete
    $combinedFuture = $future1->runAfterBothAsync($future2, function () {
        fwrite(STDERR, "Running a follow-up background task...\n");
    });

    $combinedFuture->join();

    //4.5

    $future1 = CompletableFuture::supplyAsync(function () {
        usleep(100000);
        return 2;
    });
    $future2 = CompletableFuture::supplyAsync(function () {
        return 1;
    });
 
    //non-blocking task running after either of two futures
    $resultFuture = $future1->applyToEitherAsync($future2, function ($result) {
        return $result * 2;
    });

    //4.6

    $future1 = CompletableFuture::runAsync(function () {
        // Simulate a task that takes longer
        usleep(200000);
    });
    $future2 = CompletableFuture::runAsync(function () {
        // Simulate a quicker start
        usleep(100000);
    });

    //non-blocking background task running after either of two futures
    $combinedFuture = $future1->runAfterEitherAsync($future2, function () {
        fwrite(STDERR, "Running a follow-up background task...\n");
    });

    //4.7

    //combine results of two non-blocking tasks
    $future = CompletableFuture::supplyAsync(function () {
        return "Hello";
    })->thenApplyAsync(function ($result) {
        return $result . " World";
    })->whenCompleteAsync(function ($result, $exception) {
        if ($exception == null) {
            assert(strpos($result, "World") !== false);
        }
    });

    //4.8

    $future1 = CompletableFuture::supplyAsync(function () {
        return "Hello";
    });
    $future2 = CompletableFuture::supplyAsync(function () {
        return "World";
    });

    //future that waits for all provided futures to complete
    $combinedFuture = CompletableFuture::allOf($future1, $future2);
    $combinedFuture->get();

    //4.9

     $future1 = CompletableFuture::supplyAsync(function () {
        //usleep(200000);
        return "Hello";
    });
    $future2 = CompletableFuture::supplyAsync(function () {
        return "World";
    });

    //future that waits any of the provided futures to complete
    $anyOfFuture = CompletableFuture::anyOf($future1, $future2);

```

# Running tests

```
./vendor/bin/phpunit ./tests
```

# Running docker container with examples

```
cd example
docker-compose build
docker-compose up
```

To test core usage, you can edit `TestTask` - change value `2000` in `run` method to `8000` and then run container again. When container is running check how cores are used - use `top` command, then press `f` and turn on `p` (Last used Cpu). You will see, that while container is running, different processor cores are used. 

# Dependencies

The library depends on Swoole extension and on GMP extension - the last one for handling large numbers.