<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Filesystem.php';
require dirname(__DIR__) . '/src/ArchiveExtractor.php';
require dirname(__DIR__) . '/src/IsolationGuard.php';

use ForgeFileManager\ArchiveExtractor;
use ForgeFileManager\FileError;
use ForgeFileManager\Filesystem;
use ForgeFileManager\IsolationGuard;

$root = sys_get_temp_dir() . '/ffm-integrity-' . bin2hex(random_bytes(8));
$account = $root . '/account';
$other = $root . '/other';
mkdir($root, 0700); mkdir($account, 0700); mkdir($other, 0700);
$passed = 0;
function ok(bool $condition, string $message): void {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    ++$passed;
    echo "PASS: $message\n";
}
function denied(callable $action, int $status, string $message): void {
    try { $action(); } catch (FileError $error) {
        ok($error->getCode() === $status, $message . " (HTTP {$error->getCode()})"); return;
    }
    throw new RuntimeException('FAIL: accepted ' . $message);
}
function clean(string $path): void {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) clean($entry->getPathname());
    @rmdir($path);
}
try {
    $fs = new Filesystem($account);
    $secret = $other . '/secret.ini';
    file_put_contents($secret, 'secret outside tenant');
    denied(fn()=> $fs->readText('../other/secret.ini'), 400, 'Cannot use traversal to read adjacent account');
    denied(fn()=> $fs->readText('/etc/passwd'), 400, 'Cannot read absolute system path');
    if (function_exists('symlink') && @symlink($secret, $account . '/link.ini')) {
        denied(fn()=> $fs->readText('link.ini'), 403, 'Symlink to another account rejected');
    }
    if (function_exists('link') && @link($secret, $account . '/hardlink.ini')) {
        denied(fn()=> $fs->readText('hardlink.ini'), 403, 'Hardlink to another account rejected');
    }
    denied(fn()=> IsolationGuard::assertAccount('/home', false), 503, 'Cannot target multi-account /home');
    denied(fn()=> IsolationGuard::assertAccount('/', false), 503, 'Cannot target system /');
    @symlink($account, $root . '/account-link');
    if (is_link($root . '/account-link')) denied(fn()=>IsolationGuard::assertAccount($root . '/account-link', false), 503, 'Symlink storage root is rejected');
    $priorExpected = getenv('FFM_EXPECTED_UID');
    putenv('FFM_EXPECTED_UID=99999999');
    denied(fn()=> IsolationGuard::assertAccount($account, false), 503, 'Mismatched PHP account UID rejected');
    if ($priorExpected === false) putenv('FFM_EXPECTED_UID'); else putenv('FFM_EXPECTED_UID=' . $priorExpected);
    $bind = IsolationGuard::binding('hosting', $account, 'somehash', false);
    ok($bind !== IsolationGuard::binding('hosting', $other, 'somehash', false), 'Session invalidated by account root change');
    ok($bind !== IsolationGuard::binding('hosting', $account, 'newhash', false), 'Session invalidated by password rotation');
    ok($bind !== IsolationGuard::binding('other', $account, 'somehash', false), 'Session invalidated by username change');
    ok($bind !== IsolationGuard::binding('hosting', $account, 'somehash', true), 'Sandbox session cannot cross into live mode');

    $path = $account . '/index.php';
    file_put_contents($path, "<?php echo 'before';\n");
    chmod($path, 0640);
    $past = time() - 7200;
    touch($path, $past);
    clearstatcache(true, $path);
    $initial = $fs->readText('index.php');
    ok($initial['modified'] === $past && strlen($initial['hash']) === 64, 'Editor reads modification time and revision hash');
    $new = "<?php echo 'after';\n";
    $saved = $fs->saveText('index.php', $new, $initial['hash']);
    clearstatcache(true, $path);
    ok(file_get_contents($path) === $new && hash('sha256', $new) === $saved['hash'], 'Editor saves and returns new revision');
    ok($saved['mode'] === '640' && (fileperms($path) & 0777) === 0640, 'Editor preserves original permissions');
    ok($saved['modified'] > $past, 'Editor updates last modification timestamp');
    denied(fn()=> $fs->saveText('index.php', 'stale edit', $initial['hash']), 409, 'Stale editor revision cannot overwrite newer edit');
    denied(fn()=> $fs->saveText('index.php', 'unsafe', 'invalid'), 400, 'Editor requires a valid revision token');
    ok(file_get_contents($path) === $new, 'Failed editing attempts leave original contents unchanged');

    $updated = $fs->chmodPath('index.php', '644');
    clearstatcache(true, $path);
    ok($updated['mode'] === '644' && (fileperms($path) & 0777) === 0644, 'CHMOD applies actual Unix permissions');
    ok($updated['modified'] === filemtime($path), 'CHMOD reports correct unchanged modification timestamp');
    denied(fn()=> $fs->chmodPath('index.php', '777'), 403, 'World-writable CHMOD prohibited by policy');
    denied(fn()=> $fs->chmodPath('', '755'), 403, 'CHMOD cannot alter hosting root');
    mkdir($account . '/site');
    $dirChmod = $fs->chmodPath('site', '750');
    ok($dirChmod['mode'] === '750' && (fileperms($account . '/site') & 0777) === 0750, 'CHMOD also works on folders');
    $listed = $fs->listing();
    $byName = array_column($listed['items'], null, 'name');
    ok($byName['index.php']['modified'] === $saved['modified'] && $byName['index.php']['mode'] === '644', 'Listing presents correct last-modified and CHMOD');
    ok($byName['index.php']['modifiedIso'] === gmdate('Y-m-d\\TH:i:s\\Z', $saved['modified']), 'Machine-readable UTC last-modified is stable');

    mkdir($account . '/site/assets');
    $nested = $account . '/site/assets/readme.txt';
    file_put_contents($nested, 'compress and extract');
    touch($nested, time() - 2400);
    $originMtime = filemtime($nested);
    if (class_exists(ZipArchive::class)) {
        $archive = $fs->zip('site');
        ok($archive['path'] === 'site.zip' && file_exists($account . '/site.zip'), 'Directory compression creates ZIP');
        ok($archive['entries'] >= 3 && $archive['bytes'] >= 20, 'ZIP response reports files and byte budget');
        $z = new ZipArchive();
        ok($z->open($account . '/site.zip') === true, 'Created ZIP is valid and readable');
        ok($z->getFromName('site/assets/readme.txt') === 'compress and extract', 'ZIP preserves nested content');
        $z->close();
        denied(fn()=> $fs->zip('site'), 409, 'Compression refuses to overwrite an existing ZIP');
        $unpacked = ArchiveExtractor::extract($fs, 'site.zip', '');
        ok(is_file($account . '/' . $unpacked['path'] . '/site/assets/readme.txt'), 'ZIP extracts safely to a new folder');
        $extracted = $account . '/' . $unpacked['path'] . '/site/assets/readme.txt';
        ok(abs(filemtime($extracted) - $originMtime) <= 2, 'Extraction restores original file last-modified time');
        ok((fileperms($extracted) & 0777) === 0600, 'Extracted files have restrictive 0600 permissions');
        ok((fileperms(dirname($extracted)) & 0777) === 0700, 'Extracted directories have restrictive 0700 permissions');
        $zip = $fs->zip('index.php');
        ok($zip['path'] === 'index.php.zip', 'Single file ZIP works');
        ok(array_filter(scandir($account), fn($x)=>str_starts_with($x, '.ffm-zip-')) === [], 'Compression leaves no temporary archives');
    } else {
        denied(fn()=> $fs->zip('site'), 501, 'Missing ext-zip fails explicitly, never silently');
        denied(fn()=> ArchiveExtractor::extract($fs, 'site.zip', ''), 501, 'Missing ext-zip forbids extraction explicitly');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    clean($root);
    exit(1);
}
clean($root);
echo "Integrity and isolation: $passed passed\n";
