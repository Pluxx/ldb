<?php

declare(strict_types=1);

// JSON API front controller.
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    require $root . '/src/autoload.php';
}

/** @var \Ldb\Config $config */
$config = require $root . '/src/bootstrap.php';

(new \Ldb\Kernel($config))->run();
