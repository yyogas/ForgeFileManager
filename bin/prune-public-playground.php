<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Filesystem.php';
require_once dirname(__DIR__) . '/src/PublicPlayground.php';
use ForgeFileManager\PublicPlayground;
if (PHP_SAPI !== 'cli' || $argc !== 1 || function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Run as the dedicated non-root showcase Unix user (no arguments).\n");
    exit(2);
}
try {
    // Internal CLI maintenance, not a public HTTP bypass.
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__) . '/public';
    fwrite(STDOUT, 'Expired demo workspaces removed: ' . PublicPlayground::prune() . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
