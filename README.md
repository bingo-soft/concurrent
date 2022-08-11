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

# Example 1

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

# Running tests

```
./vendor/bin/phpunit ./tests
```

# Dependencies

The library depends on [Swoole](https://openswoole.com/) extension