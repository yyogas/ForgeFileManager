<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Filesystem.php';
require_once dirname(__DIR__) . '/src/Http.php';

\ForgeFileManager\Http::handle();