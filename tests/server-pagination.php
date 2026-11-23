<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Filesystem.php';
require dirname(__DIR__) . '/src/DemoFilesystem.php';

use ForgeFileManager\Filesystem;
use ForgeFileManager\DemoFilesystem;
use ForgeFileManager\FileError;

$root = sys_get_temp_dir() . '/ffm-listing-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) exit(1);
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
}

try {
    for ($i = 1; $i <= 2052; $i++) {
        file_put_contents(sprintf('%s/file-%04d.txt', $root, $i), $i === 1 ? 'A' : str_repeat('B', $i % 10));
    }
    foreach (['adir', 'bdir', 'zdir'] as $name) mkdir($root . '/' . $name);
    file_put_contents($root . '/.hidden.txt', 'hidden');
    $fs = new Filesystem($root);
    $first = $fs->listing();
    verify(count($first['items']) === 25, 'Server returns a maximum of 25 elements');
    verify($first['total'] === 2055 && $first['pages'] === 83, 'Totals include all 2,055 visible elements, not first 2,000');
    verify($first['page'] === 1 && $first['pageSize'] === 25 && $first['hasNext'], 'Page metadata is explicit');
    verify(array_slice(array_column($first['items'], 'name'), 0, 3) === ['adir', 'bdir', 'zdir'], 'Directories sorted before files');
    verify($first['items'][3]['name'] === 'file-0001.txt', 'Files follow directory-first natural ordering');
    $second = $fs->listing('', 2);
    verify(count($second['items']) === 25 && $second['items'][0]['name'] === 'file-0023.txt', 'Second page contains only next 25 records');
    $last = $fs->listing('', 83);
    verify(count($last['items']) === 5 && !$last['hasNext'], 'Last page is bounded correctly');
    $clamped = $fs->listing('', 9999);
    verify($clamped['page'] === 83 && count($clamped['items']) === 5, 'Stale page numbers are clamped safely');
    $filtered = $fs->listing('', 1, 'file-20');
    verify($filtered['total'] === 53 && $filtered['pages'] === 3 && $filtered['items'][0]['name'] === 'file-2000.txt', 'Search covers the entire directory, including past first 2,000');
    $filteredLast = $fs->listing('', 3, 'file-20');
    verify(count($filteredLast['items']) === 3 && $filteredLast['items'][2]['name'] === 'file-2052.txt', 'Filtered final page is accurate');
    $hidden = $fs->listing('', 1, '', 'name', true);
    verify($hidden['total'] === 2056 && $hidden['items'][3]['name'] === '.hidden.txt', 'Hidden entries appear only when enabled');
    $reverse = $fs->listing('', 1, '', 'name-desc');
    verify($reverse['items'][0]['name'] === 'zdir' && $reverse['items'][3]['name'] === 'file-2052.txt', 'Reverse sort happens across entire directory');
    $bySize = $fs->listing('', 1, '', 'size');
    verify(count($bySize['items']) === 25 && $bySize['total'] === 2055, 'Size sort uses server results');
    $byDate = $fs->listing('', 1, '', 'date');
    verify(count($byDate['items']) === 25 && $byDate['total'] === 2055, 'Date sort uses server results');
    $tiny = new Filesystem($root, 1048576, 2000, 104857600, 50);
    try { $tiny->listing(); throw new RuntimeException('Expected directory scan guard'); }
    catch (FileError $e) { verify($e->getCode() === 413, 'Scan-limit overflow produces explicit 413, not misleading partial data'); }
    foreach ([0, 1000001, -1] as $badPage) {
        try { $fs->listing('', $badPage); throw new RuntimeException('Bad page accepted'); }
        catch (FileError $e) { verify($e->getCode() === 400, 'Invalid page rejected'); }
    }
    try { $fs->listing('', 1, '', 'unknown'); throw new RuntimeException('Unknown sort accepted'); }
    catch (FileError $e) { verify($e->getCode() === 400, 'Unknown sort rejected'); }
    try { $fs->listing('', 1, str_repeat('x', 201)); throw new RuntimeException('Long search accepted'); }
    catch (FileError $e) { verify($e->getCode() === 400, 'Excessive search rejected'); }
    $demo = DemoFilesystem::listing('');
    verify($demo['total'] === 3 && $demo['pageSize'] === 25, 'Virtual demo exposes the same server pagination contract');
    verify(DemoFilesystem::listing('', 2)['page'] === 1, 'Virtual demo clamps stale page to available range');
    echo "\nDirectory pagination: {$checks} passed / 0 failed\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
} finally {
    foreach (new DirectoryIterator($root) as $entry) {
        if ($entry->isDot()) continue;
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($root);
}
