<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Filesystem.php';
require_once dirname(__DIR__) . '/src/PublicShowcase.php';

use ForgeFileManager\PublicShowcase;

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php bin/prepare-public-showcase.php /absolute/private/empty/directory\n");
    exit(2);
}
try {
    PublicShowcase::initialize($argv[1]);
    fwrite(STDOUT, "Public preview sample files created. Configure FFM_PUBLIC_SHOWCASE=1 and FFM_PUBLIC_SHOWCASE_ROOT in a dedicated PHP-FPM pool.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
