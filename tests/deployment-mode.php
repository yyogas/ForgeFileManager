<?php
declare(strict_types=1);
require __DIR__ . '/../src/DeploymentMode.php';
use ForgeFileManager\DeploymentMode;
foreach (['FFM_USERNAME','FFM_PASSWORD_HASH','FFM_STORAGE_ROOT','FFM_DEMO_MODE'] as $key) putenv($key);
if (!DeploymentMode::demoEnabled()) exit('Automatic demo should start without live configuration'.PHP_EOL);
putenv('FFM_USERNAME=partial');
if (DeploymentMode::demoEnabled()) exit('Partial configuration must fail closed'.PHP_EOL);
putenv('FFM_USERNAME');
putenv('FFM_DEMO_MODE=0');
if (DeploymentMode::demoEnabled()) exit('Explicit disabled mode must be honored'.PHP_EOL);
putenv('FFM_DEMO_MODE=1');
putenv('FFM_STORAGE_ROOT=/tmp/not-a-real-root');
if (!DeploymentMode::demoEnabled()) exit('Explicit demo mode should ignore live storage configuration'.PHP_EOL);
echo "PASS: deployment mode accepts demo only when safely isolated\n";
