<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Filesystem.php';
require dirname(__DIR__) . '/src/ArchiveExtractor.php';

use ForgeFileManager\Filesystem;
use ForgeFileManager\ArchiveExtractor;
use ForgeFileManager\FileError;

if (!class_exists(ZipArchive::class)) {
    echo "SKIP: ZIP extraction tests require ext-zip (installed in CI).\n";
    exit(0);
}
$root = sys_get_temp_dir() . '/ffm-archive-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$passed=0;
function verifyZip(bool $ok, string $name): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    ++$passed;
    echo "PASS: $name\n";
}
function expectZipFailure(callable $fn, string $name): void {
    try { $fn(); } catch (FileError $error) { verifyZip(true, $name . ' (' . $error->getCode() . ')'); return; }
    throw new RuntimeException('FAIL: ' . $name . ' was accepted');
}
/** @param array<string,string> $entries */
function makeZip(string $path, array $entries): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not prepare ZIP fixture');
    foreach ($entries as $name => $content) if (!$zip->addFromString($name, $content)) throw new RuntimeException('Could not prepare ZIP entry');
    if (!$zip->close()) throw new RuntimeException('Could not finalize ZIP fixture');
}
function cleanupZip(string $path): void {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $node) if (!$node->isDot()) cleanupZip($node->getPathname());
    @rmdir($path);
}
try {
    $fs = new Filesystem($root);
    mkdir($root . '/target');
    makeZip($root.'/site.zip', ['app/index.php'=>'<?php echo "hello";', 'app/css/main.css'=>'body{color:navy}', 'README.md'=>'README']);
    $out = ArchiveExtractor::extract($fs,'site.zip','target');
    verifyZip($out['path']==='target/site (extracted)', 'ZIP extracts into an isolated new directory');
    verifyZip($out['entries']===3 && $out['bytes']===strlen('<?php echo "hello";')+strlen('body{color:navy}')+6, 'ZIP returns correct entry and byte totals');
    verifyZip(file_get_contents($root.'/target/site (extracted)/app/css/main.css')==='body{color:navy}', 'Nested directory and file contents preserved');
    verifyZip((fileperms($root.'/target/site (extracted)/app/index.php') & 0777)===0600, 'Extracted files have restrictive permissions');
    verifyZip((fileperms($root.'/target/site (extracted)/app') & 0777)===0700, 'Extracted directories have restrictive permissions');
    $second = ArchiveExtractor::extract($fs,'site.zip','target');
    verifyZip($second['path']==='target/site (extracted 2)', 'Existing target not overwritten');
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'../site.zip','target'), 'Archive traversal denied');
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'site.zip','../target'), 'Destination traversal denied');
    makeZip($root.'/traversal.zip',['../escape.txt'=>'bad']);
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'traversal.zip','target'), 'ZIP slip traversal rejected');
    verifyZip(!file_exists($root.'/escape.txt'), 'ZIP slip cannot create a sibling file');
    makeZip($root.'/absolute.zip',['/outside.txt'=>'bad']);
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'absolute.zip','target'), 'Absolute ZIP entry rejected');
    makeZip($root.'/windows.zip',['C:/Windows/System32/file'=>'bad']);
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'windows.zip','target'), 'Drive-qualified ZIP entry rejected');
    makeZip($root.'/backslash.zip',['dir\\..\\bad'=>'bad']);
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'backslash.zip','target'), 'Backslash ZIP entry rejected');
    makeZip($root.'/bomb.zip',['large.txt'=>str_repeat('A', 1048576)]);
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'bomb.zip','target'), 'High expansion ratio refused');
    $zip = new ZipArchive();$zip->open($root.'/link.zip',ZipArchive::CREATE);
    $zip->addFromString('link', '../../outside');
    $zip->setExternalAttributesName('link',ZipArchive::OPSYS_UNIX,0120777 << 16);
    $zip->close();
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'link.zip','target'), 'Unix symlink refused');
    makeZip($root.'/conflict.zip',['file'=>'text','file/child'=>'bad']);
    expectZipFailure(fn()=>ArchiveExtractor::extract($fs,'conflict.zip','target'), 'File/directory conflict rejected');
    $staging=array_filter(scandir($root.'/target'),fn($p)=>str_starts_with($p,'.ffm-extract-'));
    verifyZip($staging===[], 'Invalid archives leave no extraction staging directory');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    cleanupZip($root);
    exit(1);
}
cleanupZip($root);
echo "ZIP extraction: $passed passed\n";
