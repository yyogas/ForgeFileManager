<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Filesystem.php';
require_once dirname(__DIR__) . '/src/PublicPlayground.php';
require_once dirname(__DIR__) . '/src/IsolationGuard.php';
require_once dirname(__DIR__) . '/src/Trash.php';
use ForgeFileManager\PublicPlayground;
use ForgeFileManager\Filesystem;
use ForgeFileManager\FileError;
use ForgeFileManager\Trash;
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
};
$reject = static function (callable $f, string $label) use ($check): void {
    try { $f(); } catch (FileError) { $check(true,$label); return; }
    $check(false,$label);
};
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    echo "SKIP: run this suite as dedicated non-root Unix user\n";
    exit(2); // never silently count root as passing
}
$home = sys_get_temp_dir() . '/ffm-public-playground-' . bin2hex(random_bytes(8));
mkdir($home,0700);
$base = $home . '/playground';
mkdir($base,0700);
$_SERVER['HTTPS']='on';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__) . '/public';
foreach (['FFM_USERNAME','FFM_PASSWORD_HASH','FFM_STORAGE_ROOT','FFM_TRASH_ROOT','FFM_PUBLIC_SHOWCASE','FFM_PUBLIC_SHOWCASE_ROOT','FFM_TEST_SANDBOX','FFM_TEST_STORAGE_ROOT','FFM_TEST_TRASH_ROOT','FFM_DEMO_MODE','FFM_ALLOW_WORLD_WRITABLE'] as $key) putenv($key);
putenv('FFM_PUBLIC_PLAYGROUND_ROOT='.$base);
try {
    $reject(fn()=>PublicPlayground::base(), 'Playground is opt-in; disabled initially');
    $check(PublicPlayground::initialize($base)===realpath($base), 'Initialize an empty private base');
    $reject(fn()=>PublicPlayground::initialize($base),'Setup refuses existing data');
    putenv('FFM_PUBLIC_PLAYGROUND=1');
    $check(PublicPlayground::base()===realpath($base),'Valid marker, owner and modes accepted');
    $a=PublicPlayground::createWorkspace($base); $b=PublicPlayground::createWorkspace($base);
    $check($a['id']!==$b['id'],'Unique randomly allocated session workspaces');
    $check(is_file($a['storage'].'/README.txt') && is_file($b['storage'].'/README.txt'),'Per-session examples initialized');
    $check((fileperms($a['storage']) & 0777)===0700 && (fileperms($a['trash']) & 0777)===0700,'Private workspace and trash permissions');
    $check(PublicPlayground::paths($base,$a['id'])['storage']===$a['storage'],'Valid workspace loaded');
    $reject(fn()=>PublicPlayground::paths($base,'../'.$b['id']),'Traversal cannot select a different session');
    $fa=new Filesystem($a['storage']); $fb=new Filesystem($b['storage']);
    $fa->create('', 'myfile.txt','file');
    $check(is_file($a['storage'].'/myfile.txt') && !file_exists($b['storage'].'/myfile.txt'),'User A files do not appear in User B');
    $reject(fn()=>$fa->readText('../'.$b['id'].'/README.txt'),'Cannot traverse to second workspace');
    $reject(fn()=>PublicPlayground::preflight($fa,$a['trash'],'upload',['size'=>11*1024*1024]),'Upload exceeding demo quota blocked');
    $check(PublicPlayground::usage($a['storage'],$a['trash'])['items'] < PublicPlayground::MAX_ITEMS,'Bounded resource scan counts workspace');
    $trash=new Trash($a['storage'],$a['trash']);
    $trash->discard($fa,'myfile.txt');
    $check(!file_exists($a['storage'].'/myfile.txt'),'Recycle bin accepts selected session item');
    $check(PublicPlayground::usage($b['storage'],$b['trash'])['items'] !== PublicPlayground::usage($a['storage'],$a['trash'])['items'],'Trashed items remain isolated');
    putenv('FFM_STORAGE_ROOT='.$a['storage']);
    $reject(fn()=>PublicPlayground::base(),'Public playground cannot mix with live data');
    putenv('FFM_STORAGE_ROOT');
    putenv('FFM_PUBLIC_SHOWCASE=1');
    $reject(fn()=>PublicPlayground::base(),'Playground and read-only showcase mutually exclusive');
    putenv('FFM_PUBLIC_SHOWCASE');
    putenv('FFM_ALLOW_WORLD_WRITABLE=1');
    $reject(fn()=>PublicPlayground::base(),'Unsafe chmod policy disabled');
    putenv('FFM_ALLOW_WORLD_WRITABLE');
    touch($a['storage'], time()-PublicPlayground::IDLE_SECONDS-10);
    $check(PublicPlayground::prune(1)===1,'Expired first workspace reclaimed');
    $check(!is_dir($a['storage']) && !is_dir($a['trash']) && is_dir($b['storage']),'Pruning never removes active second workspace');
    echo "PUBLIC PLAYGROUND TESTS PASSED\n";
} finally {
    putenv('FFM_PUBLIC_PLAYGROUND');
    $remove = static function (string $dir) use (&$remove): void {
        foreach (new DirectoryIterator($dir) as $f) {
            if ($f->isDot()) continue;
            if ($f->isDir() && !$f->isLink()) $remove($f->getPathname());
            else unlink($f->getPathname());
        }
        rmdir($dir);
    };
    $remove($home);
}
