<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Filesystem.php';

use ForgeFileManager\FileError;
use ForgeFileManager\Filesystem;

$root = sys_get_temp_dir() . '/ffm-advanced-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$passed = 0;
$failed = 0;
function check(bool $ok, string $name): void
{
    global $passed, $failed;
    if ($ok) { ++$passed; echo "PASS: $name\n"; }
    else { ++$failed; fwrite(STDERR, "FAIL: $name\n"); }
}
function refuses(callable $action, string $name): void
{
    try { $action(); check(false, $name); }
    catch (FileError) { check(true, $name); }
}
function cleanupFixture(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $item) {
        if (!$item->isDot()) cleanupFixture($item->getPathname());
    }
    rmdir($path);
}
try {
    $fs = new Filesystem($root);
    $fs->create('', 'project', 'directory');
    $fs->create('project', 'assets', 'directory');
    $fs->create('project/assets', 'main.css', 'file');
    file_put_contents($root . '/project/assets/main.css', 'body { color: navy; }');
    file_put_contents($root . '/project/readme.md', 'Proof of concept');

    $checksum = $fs->checksum('project/readme.md');
    check($checksum['algorithm'] === 'SHA-256' && $checksum['checksum'] === hash('sha256', 'Proof of concept'), 'SHA-256 checksum returns correct digest');
    refuses(fn() => $fs->checksum('project'), 'SHA-256 rejects a folder');
    refuses(fn() => $fs->checksum('../etc/passwd'), 'SHA-256 rejects path traversal');
    $first = $fs->transfer('project', '', 'copy');
    check($first['path'] === 'project (copy)', 'Copy folder in same parent assigns collision-free name');
    check(file_get_contents($root . '/project (copy)/assets/main.css') === 'body { color: navy; }', 'Nested files copied with their original contents');
    check(file_get_contents($root . '/project (copy)/readme.md') === 'Proof of concept', 'Sibling files are copied');
    check($first['filesAndFolders'] === 4 && $first['bytes'] === strlen('body { color: navy; }') + strlen('Proof of concept'), 'Copy reports a bounded entry/byte budget');
    $second = $fs->transfer('project', '', 'copy');
    check($second['path'] === 'project (copy 2)', 'Multiple copies get distinct names');
    $fileCopy = $fs->transfer('project/readme.md', 'project', 'copy');
    check($fileCopy['path'] === 'project/readme (copy).md', 'Duplicate file in same folder');
    $fileCopyAgain = $fs->transfer('project/readme.md', 'project', 'copy');
    check($fileCopyAgain['path'] === 'project/readme (copy 2).md', 'Multiple duplicate filenames are unique');
    refuses(fn() => $fs->transfer('project', 'project/assets', 'copy'), 'Copying a directory inside itself is forbidden');
    refuses(fn() => $fs->transfer('project', 'project', 'copy'), 'Copying a directory to itself is forbidden');
    refuses(fn() => $fs->transfer('project', 'project/assets', 'move'), 'Moving a directory inside itself is forbidden');
    refuses(fn() => $fs->transfer('project', '', 'invalid'), 'Unknown transfer type rejected');

    if (function_exists('symlink') && @symlink('/etc/passwd', $root . '/project/assets/private-link')) {
        refuses(fn() => $fs->transfer('project', '', 'copy'), 'Reject nested symlink during directory preflight');
        check(!is_dir($root . '/project (copy 3)'), 'Failed preflight creates no destination copy');
        unlink($root . '/project/assets/private-link');
    }

    $large = fopen($root . '/project/huge.bin', 'wb');
    if ($large !== false) {
        ftruncate($large, 101 * 1024 * 1024);
        fclose($large);
        refuses(fn() => $fs->transfer('project', '', 'copy'), 'Reject folder copy when aggregate size exceeds 100 MiB');
        refuses(fn() => $fs->checksum('project/huge.bin'), 'Reject SHA-256 of files larger than 100 MiB');
        unlink($root . '/project/huge.bin');
    }
    // Reject an excessive number of entries even when each file is empty.
    $tiny = new Filesystem($root, 1048576, 10);
    for ($i=0; $i<12; $i++) file_put_contents($root . '/project/item-' . $i, '');
    refuses(fn() => $tiny->transfer('project', '', 'copy'), 'Reject copy when entry count exceeds configured boundary');
    check(!is_dir($root . '/project (copy 3)'), 'Rejected copies do not leave staging directories');
    $debris = array_filter(scandir($root), fn($name) => str_starts_with($name, '.ffm-copy-'));
    check($debris === [], 'Temporary copy tree is not leaked');
} catch (Throwable $e) {
    ++$failed;
    fwrite(STDERR, 'Unexpected error: ' . $e->getMessage() . "\n");
} finally {
    cleanupFixture($root);
}
echo "Advanced operations: $passed passed / $failed failed\n";
exit($failed === 0 ? 0 : 1);
