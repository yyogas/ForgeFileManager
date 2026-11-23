<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Filesystem.php';
require_once dirname(__DIR__) . '/src/PublicShowcase.php';
require_once dirname(__DIR__) . '/src/DeploymentMode.php';
require_once dirname(__DIR__) . '/src/IsolationGuard.php';

use ForgeFileManager\PublicShowcase;
use ForgeFileManager\FileError;

$fail = static function (string $why): never { throw new RuntimeException($why); };
$assert = static function (bool $ok, string $label) use ($fail): void {
    if (!$ok) $fail($label);
    echo "PASS: $label\n";
};
$reject = static function (callable $cb, string $label) use ($assert): void {
    try { $cb(); } catch (FileError) { $assert(true, $label); return; }
    $assert(false, $label);
};
$root = sys_get_temp_dir() . '/ffm-public-showcase-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$rm = static function (string $dir) use (&$rm): void {
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) continue;
        if ($item->isDir() && !$item->isLink()) $rm($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($dir);
};
try {
    foreach (['FFM_USERNAME','FFM_PASSWORD_HASH','FFM_STORAGE_ROOT','FFM_TRASH_ROOT','FFM_TEST_SANDBOX','FFM_TEST_STORAGE_ROOT','FFM_TEST_TRASH_ROOT','FFM_DEMO_MODE'] as $key) putenv($key);
    putenv('FFM_PUBLIC_SHOWCASE_ROOT=' . $root);
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__) . '/public';
    $reject(fn() => PublicShowcase::root(), 'Public showcase is disabled by default');
    PublicShowcase::initialize($root);
    $assert(is_file($root.'/public_html/index.html'), 'Dedicated sample site created');
    $assert((fileperms($root) & 0077) === 0, 'Private showcase root');
    putenv('FFM_PUBLIC_SHOWCASE=1');
    $assert(PublicShowcase::root() === realpath($root), 'Public showcase only when explicitly enabled');
    $reject(fn()=>PublicShowcase::assertAllowedPath('.forge-public-showcase.json'), 'Marker hidden from API');
    $reject(fn()=>PublicShowcase::assertAllowedPath('private/other-secret.txt'), 'Non-sample path denied');
    PublicShowcase::assertAllowedPath('public_html/index.html');
    $assert(true,'Known public sample path permitted');
    putenv('FFM_STORAGE_ROOT=' . $root);
    $reject(fn()=>PublicShowcase::root(),'Live credential/storage mix denied');
    putenv('FFM_STORAGE_ROOT');
    $reject(fn()=>PublicShowcase::initialize($root),'Setup cannot overwrite an existing directory');
    file_put_contents($root.'/private/secret.txt','secret-not-public');
    $reject(fn()=>PublicShowcase::root(),'Unexpected private file stops publication');
    unlink($root.'/private/secret.txt');
    $assert(PublicShowcase::root() === realpath($root),'Manifest recovers after rogue file is removed');
    file_put_contents($root.'/public_html/index.html','replaced');
    $reject(fn()=>PublicShowcase::root(),'Altered sample data stops publication');
} finally {
    $rm($root);
}
echo "PUBLIC SHOWCASE PHP TESTS PASSED\n";
