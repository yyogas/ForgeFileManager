<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Filesystem.php';
require_once dirname(__DIR__) . '/src/PublicPlayground.php';
use ForgeFileManager\PublicPlayground;
if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php bin/prepare-public-playground.php /absolute/path/to/empty/private-root\n");
    exit(2);
}
try {
    $root = PublicPlayground::initialize($argv[1]);
    fwrite(STDOUT, "Initialized isolated public playground in: {$root}\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
