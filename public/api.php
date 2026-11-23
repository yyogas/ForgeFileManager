<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Filesystem.php';
require_once dirname(__DIR__) . '/src/DirectoryPager.php';
require_once dirname(__DIR__) . '/src/Trash.php';
require_once dirname(__DIR__) . '/src/ArchiveExtractor.php';
require_once dirname(__DIR__) . '/src/BulkTransfer.php';
require_once dirname(__DIR__) . '/src/DeploymentMode.php';
require_once dirname(__DIR__) . '/src/IsolationGuard.php';
require_once dirname(__DIR__) . '/src/Http.php';

\ForgeFileManager\Http::handle();
