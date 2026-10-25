<?php

declare(strict_types=1);

// SPA shell: everything is rendered client-side into #app.
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    require $root . '/src/autoload.php';
}

/** @var \Ldb\Config $config */
$config = require $root . '/src/bootstrap.php';

$theme = (string) $config->get('theme_default', 'system');
$theme = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
$version = rawurlencode(\Ldb\Version::get());
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="en" dir="ltr" data-theme="<?= $e($theme) ?>" data-theme-default="<?= $e((string) $config->get('theme_default', 'system')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="referrer" content="no-referrer">
<title>LDB</title>
<link rel="icon" type="image/svg+xml" href="assets/dist/favicon.svg">
<link rel="stylesheet" href="assets/dist/app.css?v=<?= $version ?>">
<script src="assets/dist/theme.js?v=<?= $version ?>"></script>
</head>
<body>
<div id="app"></div>
<script type="module" src="assets/dist/app.js?v=<?= $version ?>"></script>
</body>
</html>
