<?php

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

use Example\Config\Bootstrap;

try {
    $application = new Bootstrap();
    $application->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
