<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Filesystem.php';
require dirname(__DIR__) . '/src/Trash.php';

use ForgeFileManager\FileError;
use ForgeFileManager\Filesystem;
use ForgeFileManager\Trash;

$base = sys_get_temp_dir() . '/ffm-trash-test-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
mkdir($base . '/hosting', 0700);
mkdir($base . '/private-trash', 0700);
$fs = new Filesystem($base . '/hosting');
$bin = new Trash($fs->root(), $base . '/private-trash');
$total = 0;
function checkTrash(bool $v, string $description): void {
    global $total;
    if (!$v) throw new RuntimeException('FAIL: ' . $description);
    $total++; echo "PASS: $description\n";
}
function refusesTrash(callable $callback, string $label): void {
    try {$callback();} catch (FileError) { checkTrash(true, $label); return; }
    checkTrash(false, $label);
}
function cleanTrash(string $directory): void {
    if (!is_dir($directory) || is_link($directory)) { @unlink($directory); return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $node) {
        if ($node->isDir() && !$node->isLink()) @rmdir($node->getPathname());
        else @unlink($node->getPathname());
    }
    @rmdir($directory);
}
try {
    file_put_contents($base . '/hosting/contract.txt', 'contract-A');
    $entry = $bin->discard($fs, 'contract.txt');
    checkTrash(!file_exists($base . '/hosting/contract.txt'), 'Recycling removes original without irreversible deletion');
    checkTrash(preg_match('/^[0-9a-f]{32}$/', $entry['id']) === 1, 'Private entry IDs are cryptographically random');
    checkTrash($bin->listing()['total'] === 1, 'Private recycle bin lists moved content');
    refusesTrash(fn() => $bin->restore($fs, '../etc/passwd'), 'Reject path traversal as recycle-bin ID');
    file_put_contents($base . '/hosting/contract.txt', 'another file');
    refusesTrash(fn() => $bin->restore($fs, $entry['id']), 'Never overwrite current file on restore');
    checkTrash(file_get_contents($base . '/hosting/contract.txt') === 'another file', 'Existing file content remains intact after conflict');
    unlink($base . '/hosting/contract.txt');
    $restored = $bin->restore($fs, $entry['id']);
    checkTrash($restored['path'] === 'contract.txt' && file_get_contents($base . '/hosting/contract.txt') === 'contract-A', 'Restore exact original bytes');
    checkTrash($bin->listing()['total'] === 0, 'Restored entry removed from private bin');
    $fs->create('', 'nested', 'directory');
    $fs->create('nested', 'children', 'directory');
    file_put_contents($base . '/hosting/nested/children/data.txt', 'dir-data');
    $nested = $bin->discard($fs, 'nested');
    checkTrash(!is_dir($base . '/hosting/nested') && $bin->listing()['total'] === 1, 'Recycle populated folders');
    $bin->restore($fs, $nested['id']);
    checkTrash(file_get_contents($base . '/hosting/nested/children/data.txt') === 'dir-data', 'Restore complete populated folders');
    @symlink('/etc/passwd', $base . '/hosting/nested/children/unsafe-link');
    if (is_link($base . '/hosting/nested/children/unsafe-link')) {
        refusesTrash(fn() => $bin->discard($fs, 'nested'), 'Reject directories containing symlinks');
        unlink($base . '/hosting/nested/children/unsafe-link');
    }
    refusesTrash(fn() => $bin->discard($fs, ''), 'Never recycle the account root');
    refusesTrash(fn() => new Trash($base . '/hosting', $base . '/hosting/nested'), 'Never place trash beneath customer root');
    $badPermissions = $base . '/permissive';
    mkdir($badPermissions, 0777); chmod($badPermissions, 0777);
    refusesTrash(fn() => new Trash($base . '/hosting', $badPermissions), 'Reject public-readable private recycle-bin location');
    echo "Trash suite PASS: $total checks\n";
} finally {
    cleanTrash($base);
}
