<?php

declare(strict_types=1);

/*
 * Common bootstrap for public/index.php and public/api.php:
 * error handling, configuration and security headers. Returns the Config instance.
 */

use Ldb\Config;

if (!class_exists(Config::class)) {
    require __DIR__ . '/autoload.php';
}

$config = Config::load(dirname(__DIR__) . '/config/config.php');

error_reporting(E_ALL);
ini_set('display_errors', $config->bool('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// Turn PHP warnings/notices into exceptions so the API never emits half-broken JSON.
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity) || ($severity & (E_DEPRECATED | E_USER_DEPRECATED))) {
        return false; // silenced with @, or deprecation notice: leave to the default logger
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
        . "script-src 'self'; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    $https = (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

return $config;
