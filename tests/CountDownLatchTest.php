<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Concurrent\Lock\{
    CountDownLatch,
    LockSupport
};
use Concurrent\Worker\InterruptibleProcess;

class CountDownLatchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        //Initialize IPC at port 1081
        LockSupport::init(1082);
    }

    public function testMethods(): void
    {
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
        $this->assertTrue(floor(($end - $start) / 1000000) < 5000);
    }
}
