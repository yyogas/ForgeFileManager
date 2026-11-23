<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Filesystem.php';
require dirname(__DIR__) . '/src/BulkTransfer.php';

use ForgeFileManager\Filesystem;
use ForgeFileManager\BulkTransfer;
use ForgeFileManager\FileError;

$root = sys_get_temp_dir() . '/ffm-bulk-' . bin2hex(random_bytes(8));
mkdir($root,0700); mkdir($root.'/destination');
$passed=0;
function bulkAssert(bool $ok, string $why): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL: '.$why);
    ++$passed; echo 'PASS: '.$why."\n";
}
function bulkRefuse(callable $op,string $why):void {
    try{$op();}catch(FileError){bulkAssert(true,$why);return;}
    throw new RuntimeException('FAIL: '.$why);
}
function bulkCleanup(string $p):void {
    if(is_file($p)||is_link($p)){@unlink($p);return;}
    if(!is_dir($p))return;
    foreach(new DirectoryIterator($p) as $e)if(!$e->isDot())bulkCleanup($e->getPathname());
    @rmdir($p);
}
try {
    $fs = new Filesystem($root);
    file_put_contents($root.'/alpha.txt','Alpha');file_put_contents($root.'/beta.txt','Beta');
    $copy=BulkTransfer::execute($fs,['alpha.txt','beta.txt'],'destination','copy');
    bulkAssert($copy['success']===2 && $copy['failed']===0 && count($copy['results'])===2,'Two files copied in one request');
    bulkAssert(is_file($root.'/destination/alpha.txt') && is_file($root.'/alpha.txt'),'Bulk copy keeps originals');
    $move=BulkTransfer::execute($fs,['alpha.txt','beta.txt'],'destination','move');
    bulkAssert($move['success']===0 && $move['failed']===2,'Conflicting moves report per-item failures without overwrite');
    bulkAssert(file_get_contents($root.'/destination/beta.txt')==='Beta','Conflicted destination content unchanged');
    mkdir($root.'/folder');file_put_contents($root.'/folder/child','child');
    $mixed=BulkTransfer::execute($fs,['folder','alpha.txt'],'destination','copy');
    bulkAssert($mixed['success']===2 && $mixed['failed']===0,'Bulk copy accepts folder and file');
    bulkAssert(is_file($root.'/destination/folder/child'),'Nested folder tree preserved');
    bulkRefuse(fn()=>BulkTransfer::execute($fs,[], 'destination','copy'),'Empty bulk selection refused');
    bulkRefuse(fn()=>BulkTransfer::execute($fs,array_fill(0,26,'alpha.txt'),'destination','copy'),'More than 25 selections refused');
    bulkRefuse(fn()=>BulkTransfer::execute($fs,['alpha.txt','alpha.txt'],'destination','copy'),'Repeated paths refused');
    bulkRefuse(fn()=>BulkTransfer::execute($fs,['folder','folder/child'],'destination','copy'),'Ancestor and child in same selection refused');
    bulkRefuse(fn()=>BulkTransfer::execute($fs,['../private'],'destination','move'),'Path traversal refused');
    bulkRefuse(fn()=>BulkTransfer::execute($fs,['alpha.txt'],'destination','delete'),'Unsupported bulk operation refused');
    bulkAssert(file_get_contents($root.'/alpha.txt')==='Alpha','Invalid bulk requests leave original files unchanged');
} catch(Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");bulkCleanup($root);exit(1);
}
bulkCleanup($root);
echo "Bulk transfer: $passed passed\n";
