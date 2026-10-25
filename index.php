<?php

declare(strict_types=1);

/*
 * Fallback entry point when the repository root is the web root and mod_rewrite is not
 * available: redirect to public/ so relative asset and API URLs resolve correctly.
 */
$base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
header('Location: ' . $base . '/public/', true, 302);
exit;
