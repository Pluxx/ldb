<?php

declare(strict_types=1);

/*
 * Minimal PSR-4 autoloader used when vendor/autoload.php is absent
 * (e.g. deployments without Composer). Maps Ldb\Foo\Bar to src/Foo/Bar.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ldb\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
