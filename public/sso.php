<?php

declare(strict_types=1);

// Single sign-on entry point for control panels: sso.php?token=… (or POST field "token").
// See docs/sso.md. Never logs the token; failures show a generic page.
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    require $root . '/src/autoload.php';
}

/** @var \Ldb\Config $config */
$config = require $root . '/src/bootstrap.php';

try {
    $request = \Ldb\Http\Request::fromGlobals();
    $response = (new \Ldb\Auth\SsoEndpoint($config))->handle($request);
} catch (\Throwable $e) {
    // Messages of these exceptions never contain the token (it is not passed to them).
    error_log('[ldb] SSO error: ' . get_class($e) . ': ' . $e->getMessage());
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Single sign-on failed.\n";
    exit;
}
$response->send();
