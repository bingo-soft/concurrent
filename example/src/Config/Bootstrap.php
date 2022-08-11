<?php

namespace Example\Config;

use Phalcon\Di;
use Phalcon\Cli\Console as ConsoleApp;
use Phalcon\Cli\Dispatcher;
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Config;

class Bootstrap
{
    /**
     * Bootstrap constructor.
     */
    public function __construct()
    {
        $config = [
        ];

        $this->config = new Config($config);
    }

    public function run(): void
    {
        $this->di = new CliDI();
        $this->di->set("bootstrap", $this);

        $dispatcher = new Dispatcher();
        $dispatcher->setDefaultNamespace("Example\Task");
        $this->di->set("dispatcher", $dispatcher);

        $console = new ConsoleApp();
        $console->setDI($this->di);
        $this->di->setShared("console", $console);

        $di = new Di();
        $di->setDefault($this->di);

        $arguments = [];
        $argv = $_SERVER['argv'];
        if (isset($argv) && !empty($argv)) {
            foreach ($argv as $k => $arg) {
                if ($k === 1) {
                    $arguments['task'] = $arg;
                } elseif ($k === 2) {
                    $arguments['action'] = $arg;
                } elseif ($k >= 3) {
                    $arguments['params'][] = $arg;
                }
            }
        }
        $console->handle($arguments);       
    }
}
