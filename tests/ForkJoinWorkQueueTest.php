<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Concurrent\Queue\ForkJoinWorkQueue;
use Concurrent\Executor\ForkJoinPoolExecutor;
use Concurrent\Lock\{
    LockSupport,
    ReentrantLockNotification
};
use Concurrent\Task\ForkJoinTask;
use Concurrent\Worker\InterruptibleProcess;
use Swoole\Coroutine\Channel;

class ForkJoinWorkQueueTest extends TestCase
{
    protected function setUp(): void
    {
    }

    public function testMethods(): void
    {
        $pool = new ForkJoinPoolExecutor();
        $t1 = new ForkJoinTask();
        $t2 = new ForkJoinTask();
        $t3 = new ForkJoinTask();
        $t4 = new ForkJoinTask();
        $t5 = new ForkJoinTask();
        $t6 = new ForkJoinTask();

        $queue = new ForkJoinWorkQueue(null, null, 5);
        $queue->push($t1);
        $queue->push($t2);
        $queue->push($t3);
        $queue->push($t4);
        $queue->push($t5);
        $t = $queue->pop();
        $this->assertTrue($t->equals($t5)); //[1, 2, 3, 4]
        $this->assertEquals(4, $queue->queueSize());
        $queue->push($t5); //[1, 2, 3, 4, 5]
        $this->assertEquals(5, $queue->queueSize());
        $t = $queue->poll(); //[2, 3, 4, 5]
        $this->assertTrue($t->equals($t1));
        $this->assertEquals(4, $queue->queueSize());
        $t = $queue->poll(); //[3, 4, 5]
        $this->assertTrue($t->equals($t2));
        $this->assertEquals(3, $queue->queueSize());
        $queue->push($t1); //[3, 4, 5, 1]
        $queue->push($t2); //[3, 4, 5, 1, 2]
        $t = $queue->pop(); //[3, 4, 5, 1]
        $this->assertTrue($t->equals($t2));
        $t = $queue->pollAt(0); //[4, 5, 1]
        $this->assertEquals(3, $queue->queueSize());
        $queue->push($t2); //[4, 5, 1, 2]
        $queue->push($t3); //[4, 5, 1, 2, 3]
        $this->assertEquals(5, $queue->queueSize());
        $t = $queue->pollAt(0); //[5, 1, 2, 3]
        $this->assertTrue($t->equals($t4));
        $t = $queue->pollAt(0); //[1, 2, 3]
        $this->assertTrue($t->equals($t5));
        $t = $queue->pollAt(0); //[2, 3]
        $this->assertTrue($t->equals($t1));
        $t = $queue->pollAt(0); //[3]
        $this->assertTrue($t->equals($t2));
        $t = $queue->pollAt(0); //[]
        $this->assertTrue($t->equals($t3));
        $queue->push($t1);
        $queue->push($t2);
        $queue->push($t3); //[1, 2, 3]
        $t = $queue->poll(); //[2, 3]
        $this->assertTrue($t->equals($t1));
        $t = $queue->pop(); //[2]
        $this->assertTrue($t->equals($t3));
        $this->assertEquals(1, $queue->queueSize());
        $queue->push($t1);
        $queue->push($t3);
        $queue->push($t4);
        $queue->push($t5);
        $this->assertEquals(5, $queue->queueSize());
        $t = $queue->peek();
        $this->assertTrue($t->equals($t5));
        $this->assertEquals(5, $queue->queueSize());
        $this->assertFalse($queue->tryUnpush($t1));
        $this->assertTrue($queue->tryUnpush($t5));
        $this->assertEquals(4, $queue->queueSize());
        $this->assertTrue($queue->tryRemoveAndExec($t4));
        $this->assertTrue($queue->tryRemoveAndExec($t1));
        $this->assertFalse($queue->tryRemoveAndExec($t1));
        $this->assertTrue($queue->tryRemoveAndExec($t2));
        $this->assertFalse($queue->tryRemoveAndExec($t4));


        $table = new \Swoole\Table(1024);
        $table->column('name', \Swoole\Table::TYPE_STRING, 64);
        $table->column('id', \Swoole\Table::TYPE_INT, 4);       //1,2,4,8
        $table->column('num', \Swoole\Table::TYPE_FLOAT);
        $table->create();

        $table->set('a', array('id' => 1, 'name' => 'swoole-co-uk', 'num' => 3.1415));
        $table->set('b', ['id' => 0]);

        LockSupport::init(1081);

        $p = new InterruptibleProcess(function ($process) {
            fwrite(STDERR, "Start process " . $process->pid . "\n");
            LockSupport::park($process);         
            fwrite(STDERR, "Process " . $process->pid . " ended\n");
        });
        $p->start();
        $p2 = new InterruptibleProcess(function ($process) use ($lock) {
            fwrite(STDERR, "Start process " . $process->pid . "\n");
            LockSupport::park($process);         
            fwrite(STDERR, "Process " . $process->pid . " ended\n");
        });
        $p2->start();

        sleep(2);
        fwrite(STDERR, "Wakeup processes\n");
        LockSupport::unpark($p2->pid);
        LockSupport::unpark($p->pid);
        $p->wait();
        $p2->wait();


        
        /*$p3 = new InterruptibleProcess(function ($process) use ($lock) {
            fwrite(STDERR, "Start process " . $process->pid . " and return from park immediately\n");
            //$lock->wait(0);
            //$read = $process->read();
            //$read = $process->pop();
            LockSupport::park($process);         
            fwrite(STDERR, "Process " . $process->pid . " ended\n");
        });
        $p3->start();
        fwrite(STDERR, "Unpark before parking\n");
        LockSupport::unpark($p3->pid);
        fwrite(STDERR, "Wait process to end\n");
        $p3->wait();*/
    }    
}
