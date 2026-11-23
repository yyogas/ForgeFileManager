<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Filesystem.php';

use ForgeFileManager\FileError;
use ForgeFileManager\Filesystem;

$root = sys_get_temp_dir() . '/ffm-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$failed = 0;
$passed = 0;
function assertTrue(bool $success, string $message): void {
    global $failed, $passed;
    if (!$success) {fwrite(STDERR, "FAIL: $message\n");$failed++;}
    else {$passed++;echo "PASS: $message\n";}
}
function expectFileError(callable $callback, string $message): void {
    $thrown=false;
    try {$callback();} catch (FileError) {$thrown=true;}
    assertTrue($thrown,$message);
}
try {
    $fs = new Filesystem($root);
    assertTrue($fs->listing()['items']===[],'Initial directory empty');
    $fs->create('','public_html','directory');
    $fs->create('public_html','index.php','file');
    assertTrue(is_file($root.'/public_html/index.php'),'Create file in authorized directory');
    assertTrue(count($fs->listing('public_html')['items'])===1,'List relative files');
    expectFileError(fn()=>$fs->listing('../'), 'Reject traversal with dot-dot');
    expectFileError(fn()=>$fs->listing('/etc'), 'Reject absolute paths');
    expectFileError(fn()=>$fs->listing('public_html//..'), 'Reject empty segments');
    expectFileError(fn()=>$fs->create('public_html','../escape','file'), 'Reject slash in filename');
    expectFileError(fn()=>$fs->create('public_html','..','file'), 'Reject dot-dot filename');
    expectFileError(fn()=>$fs->chmodPath('','755'), 'Never CHMOD account root');
    expectFileError(fn()=>$fs->chmodPath('public_html/index.php','777'), 'Block world-writable CHMOD by default');
    $mode=$fs->chmodPath('public_html/index.php','640');
    assertTrue($mode['mode']==='640','Apply CHMOD 640');
    $initial=$fs->readText('public_html/index.php');
    assertTrue($initial['content']==='','Read empty text');
    $written=$fs->saveText('public_html/index.php','<?php echo "safe";', $initial['hash']);
    assertTrue($written['hash']===hash('sha256','<?php echo "safe";'),'Safe edit with expected hash');
    expectFileError(fn()=>$fs->saveText('public_html/index.php','overwrite','00000'), 'Reject stale editor hash');
    $renamed=$fs->renamePath('public_html/index.php','homepage.php');
    assertTrue($renamed['path']==='public_html/homepage.php','Rename file');
    $copied=$fs->transfer('public_html/homepage.php','public_html','copy');
    assertTrue($copied['path']==='public_html/homepage (copy).php','Copy file without overwriting');
    $fs->create('','backups','directory');
    $moved=$fs->transfer('public_html/homepage (copy).php','backups','move');
    assertTrue($moved['path']==='backups/homepage (copy).php','Move file to another directory');
    expectFileError(fn()=>$fs->transfer('public_html','public_html','move'), 'Reject moving directory into itself');
    expectFileError(fn()=>$fs->remove('public_html'), 'Reject recursive delete of non-empty directory');
    if (function_exists('symlink')) {
        @symlink('/etc', $root.'/public_html/escape');
        if (is_link($root.'/public_html/escape')) {
            expectFileError(fn()=>$fs->listing('public_html/escape'), 'Block symlink escape');
            expectFileError(fn()=>$fs->readText('public_html/escape/passwd'), 'Block symlink child access');
        }
    }
    $fs->remove('backups/homepage (copy).php');
    $fs->remove('backups');
    assertTrue(!file_exists($root.'/backups'),'Delete empty directory');
    $fs->remove('public_html/homepage.php');
    if (is_link($root.'/public_html/escape')) {
        expectFileError(fn()=>$fs->remove('public_html/escape'), 'Symlink deletion requires out-of-band admin action');
        unlink($root.'/public_html/escape');
    }
    $fs->remove('public_html');
    assertTrue(!is_dir($root.'/public_html'), 'Remove empty parent folder after cleanup');
} catch (Throwable $exception) {
    fwrite(STDERR,'Unexpected exception: '.$exception->getMessage().PHP_EOL);$failed++;
} finally {
    if (is_link($root.'/public_html/escape')) unlink($root.'/public_html/escape');
    if (is_dir($root.'/public_html')) rmdir($root.'/public_html');
    if (is_dir($root.'/backups')) rmdir($root.'/backups');
    if (is_dir($root)) rmdir($root);
}
echo "\nPassed: $passed / Failed: $failed\n";
exit($failed>0?1:0);