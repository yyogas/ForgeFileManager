<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Filesystem.php';
require dirname(__DIR__) . '/src/DeploymentMode.php';
use ForgeFileManager\DeploymentMode;
use ForgeFileManager\FileError;
foreach (['FFM_USERNAME', 'FFM_PASSWORD_HASH', 'FFM_STORAGE_ROOT', 'FFM_DEMO_MODE', 'FFM_TEST_SANDBOX', 'FFM_ALLOW_HTTP_DEV', 'FFM_TEST_STORAGE_ROOT'] as $name) putenv($name);
if (DeploymentMode::sandboxEnabled()) throw new RuntimeException('Sandbox must not be automatic');
putenv('FFM_DEMO_MODE=1');
if (DeploymentMode::sandboxEnabled()) throw new RuntimeException('Legacy demo must not auto-start');
putenv('FFM_TEST_SANDBOX=1');
if (!DeploymentMode::sandboxEnabled()) throw new RuntimeException('Explicit test profile not recognized');
$directory = sys_get_temp_dir() . '/ffm-test-profile-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    putenv('FFM_TEST_STORAGE_ROOT=' . $directory);
    putenv('FFM_ALLOW_HTTP_DEV=1');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
    try { DeploymentMode::sandboxRoot(); throw new RuntimeException('Public remote IP should be denied'); } catch (FileError) {}
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    if (DeploymentMode::sandboxRoot() !== realpath($directory)) throw new RuntimeException('Sandbox root not accepted');
    putenv('FFM_STORAGE_ROOT=' . $directory);
    try { DeploymentMode::sandboxRoot(); throw new RuntimeException('Mixed live/test roots should fail'); } catch (FileError) {}
    putenv('FFM_STORAGE_ROOT');
    putenv('FFM_ALLOW_HTTP_DEV');
    try { DeploymentMode::sandboxRoot(); throw new RuntimeException('Sandbox should require dev HTTP flag'); } catch (FileError) {}
    echo "PASS: default demo removed; sandbox requires explicit opt-in, localhost and isolation\n";
} finally {
    rmdir($directory);
}
