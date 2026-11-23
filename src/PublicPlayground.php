<?php
declare(strict_types=1);

namespace ForgeFileManager;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ephemeral, writable public demo workspaces for untrusted visitors.
 * Every login gets a random, isolated namespace outside the webroot.
 * The dedicated PHP-FPM worker must itself be isolated in a container or OS
 * service account with NO access to hosting data and an OS-enforced quota.
 * Application checks are defense in depth, NOT a hard resource sandbox.
 */
final class PublicPlayground
{
    public const MARKER = '.forge-public-playground.json';
    private const MARKER_DATA = "{\"product\":\"Forge File Manager\",\"profile\":\"public-playground\",\"version\":1}\n";
    private const ID_PATTERN = '/^[a-f0-9]{32}$/D';
    public const IDLE_SECONDS = 1800;
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_ITEMS = 250;
    public const MAX_SESSIONS = 30;
    public const MAX_UPLOAD_BYTES = 1024 * 1024;

    public static function enabled(): bool
    {
        return getenv('FFM_PUBLIC_PLAYGROUND') === '1';
    }

    private static function within(string $subject, string $base): bool
    {
        return $subject === $base || str_starts_with($subject, rtrim($base, '/') . '/');
    }

    private static function assertOutsideHttp(string $root): void
    {
        foreach ([realpath(dirname(__DIR__)), realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''))] as $base) {
            if ($base && self::within($root, $base)) {
                throw new FileError('Public playground storage must be outside application and web directories.', 503);
            }
        }
    }

    private static function assertPrivateDirectory(string $path, int $uid): void
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000 || ($st['mode'] & 0077) !== 0 ||
            $st['uid'] !== $uid || !is_writable($path)) {
            throw new FileError('Public playground directory must be owner-only (0700) and owned by the dedicated PHP worker.', 503);
        }
    }

    private static function assertSafeBase(string $provided): string
    {
        if ($provided === '' || is_link($provided)) {
            throw new FileError('An existing private public-playground directory is required.', 503);
        }
        $root = realpath($provided);
        if ($root === false || !is_dir($root) || in_array($root, ['/', '/home', '/srv', '/var', '/var/www', '/tmp', '/etc', '/opt', '/root'], true)) {
            throw new FileError('Public playground cannot use a system or shared directory.', 503);
        }
        self::assertOutsideHttp($root);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($uid === null || $uid === 0) {
            throw new FileError('Public playground requires a dedicated non-root Unix identity.', 403);
        }
        self::assertPrivateDirectory($root, $uid);
        return $root;
    }

    /** Initialize an EMPTY, explicitly chosen folder as a non-root CLI user. */
    public static function initialize(string $provided): string
    {
        if (PHP_SAPI !== 'cli') {
            throw new FileError('Public playground setup is CLI-only.', 403);
        }
        $root = self::assertSafeBase($provided);
        if (count(scandir($root) ?: []) !== 2) {
            throw new FileError('Public playground setup requires an empty directory; existing data will not be overwritten.', 409);
        }
        umask(0077);
        foreach (['sessions', 'trash'] as $dir) {
            if (!mkdir($root . '/' . $dir, 0700)) {
                throw new FileError('Failed to initialize private public playground.', 503);
            }
        }
        $n = file_put_contents($root . '/' . self::MARKER, self::MARKER_DATA, LOCK_EX);
        if ($n !== strlen(self::MARKER_DATA)) throw new FileError('Failed to seal playground marker.', 503);
        chmod($root . '/' . self::MARKER, 0600);
        return $root;
    }

    public static function base(): string
    {
        if (!self::enabled() || getenv('FFM_PUBLIC_SHOWCASE') === '1' ||
            getenv('FFM_TEST_SANDBOX') === '1' || getenv('FFM_DEMO_MODE') === '1') {
            throw new FileError('The public playground must be enabled explicitly and cannot be combined with other modes.', 503);
        }
        foreach (['FFM_USERNAME','FFM_PASSWORD_HASH','FFM_STORAGE_ROOT','FFM_TRASH_ROOT',
                  'FFM_PUBLIC_SHOWCASE_ROOT','FFM_TEST_STORAGE_ROOT','FFM_TEST_TRASH_ROOT'] as $key) {
            if ((string) getenv($key) !== '') {
                throw new FileError('Public playground cannot be combined with live account credentials or storage.', 503);
            }
        }
        if (getenv('FFM_ALLOW_WORLD_WRITABLE') === '1') {
            throw new FileError('Public playground forbids world-writable CHMOD policy.', 503);
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $local = PHP_SAPI === 'cli-server' && getenv('FFM_ALLOW_HTTP_DEV') === '1' &&
            in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true) &&
            !isset($_SERVER['HTTP_FORWARDED']) && !isset($_SERVER['HTTP_X_FORWARDED_FOR']);
        if (!$https && !$local) throw new FileError('Public playground requires HTTPS.', 403);
        $root = self::assertSafeBase((string) getenv('FFM_PUBLIC_PLAYGROUND_ROOT'));
        $uid = (int) posix_geteuid();
        $marker = $root . '/' . self::MARKER;
        $st = @lstat($marker);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['uid'] !== $uid ||
            ($st['mode'] & 0077) !== 0 || $st['nlink'] !== 1 ||
            file_get_contents($marker) !== self::MARKER_DATA) {
            throw new FileError('Public playground marker missing or modified. Initialize it from CLI first.', 503);
        }
        foreach (['sessions','trash'] as $dir) {
            self::assertPrivateDirectory($root . '/' . $dir, $uid);
            if (@stat($root . '/' . $dir)['dev'] !== @stat($root)['dev']) {
                throw new FileError('Playground folders must share the same filesystem.', 503);
            }
        }
        return $root;
    }

    /** @return array{id:string,storage:string,trash:string} */
    public static function createWorkspace(string $base): array
    {
        $base = self::base();
        // Reclaim a small, bounded number of expired sessions before capacity check.
        self::prune(5);
        $id = bin2hex(random_bytes(16));
        $lock = @fopen($base . '/.allocation.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new FileError('Public playground capacity lock is unavailable.', 503);
        }
        try {
            $entries = scandir($base . '/sessions');
            if ($entries === false) throw new FileError('Could not enumerate public demo sessions.', 503);
            $active = 0;
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') continue;
                // Never follow or remove unexpected paths.
                if (!preg_match(self::ID_PATTERN, $name) || is_link($base . '/sessions/' . $name)) {
                    throw new FileError('Unexpected session entry: public playground disabled until inspected.', 503);
                }
                if (is_dir($base . '/sessions/' . $name)) {
                    $active++;
                }
            }
            if ($active >= self::MAX_SESSIONS) {
                throw new FileError('All public demo workspaces are busy. Try again later.', 429);
            }
            $storage = $base . '/sessions/' . $id;
            $trash = $base . '/trash/' . $id;
            umask(0077);
            if (!@mkdir($storage, 0700)) {
                throw new FileError('Could not create a private demo workspace.', 503);
            }
            if (!@mkdir($trash, 0700)) {
                @rmdir($storage);
                throw new FileError('Could not create private demo trash.', 503);
            }
            foreach (self::seedFiles() as $relative => $contents) {
                $absolute = $storage . '/' . $relative;
                if (!is_dir(dirname($absolute)) && !@mkdir(dirname($absolute), 0700, true)) {
                    throw new FileError('Could not initialize demo example folders.', 503);
                }
                if (@file_put_contents($absolute, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new FileError('Could not initialize demo example files.', 503);
                }
                @chmod($absolute, 0600);
            }
            self::assertBudget($storage, $trash);
            return ['id' => $id, 'storage' => $storage, 'trash' => $trash];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,string> */
    private static function seedFiles(): array
    {
        return [
            'README.txt' => "FORGE FILE MANAGER — INTERACTIVE PUBLIC DEMO\n\nYou have full FILE MANAGER permissions within your own temporary sandbox.\nChange files, edit CHMOD, upload, zip/unzip and use the recycle bin.\nYour files are NEVER web-served or executable. They expire when the demo workspace expires.\nNo access to the real hosting server or other visitors' files is possible through the application.\n",
            'public_html/index.html' => "<!doctype html>\n<html lang=\"en\"><meta charset=\"utf-8\"><title>Demo site</title><h1>Editable demo website</h1></html>\n",
            'public_html/style.css' => "body { font-family: system-ui, sans-serif; background: white; }\n",
            'logs/README.txt' => "Example logs. No real server logs are available.\n",
            'backups/README.txt' => "This is a demonstration. No real backups are stored here.\n",
        ];
    }

    /** @return array{storage:string,trash:string} */
    public static function paths(string $base, mixed $id): array
    {
        if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
            throw new FileError('Demo session has no valid private workspace. Sign in again.', 401);
        }
        $root = self::base();
        $storage = $root . '/sessions/' . $id;
        $trash = $root . '/trash/' . $id;
        $uid = (int) posix_geteuid();
        self::assertPrivateDirectory($storage, $uid);
        self::assertPrivateDirectory($trash, $uid);
        if (@stat($storage)['dev'] !== @stat($trash)['dev']) {
            throw new FileError('Demo workspace/trash must be on the same filesystem.', 503);
        }
        if (time() - (int) @filemtime($storage) > self::IDLE_SECONDS) {
            throw new FileError('Demo workspace has expired. Log in again.', 401);
        }
        @touch($storage);
        return ['storage'=>$storage, 'trash'=>$trash];
    }

    /** @return array{bytes:int,items:int} */
    public static function usage(string $storage, string $trash): array
    {
        $bytes = 0;
        $items = 0;
        foreach ([$storage, $trash] as $base) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                if ($file->isLink() || (!$file->isFile() && !$file->isDir())) {
                    throw new FileError('Unsafe entry in public playground workspace.', 403);
                }
                $stat = @lstat($file->getPathname());
                if ($stat === false || $stat['nlink'] > ($file->isDir() ? 1000 : 1)) {
                    throw new FileError('Unexpected linked file in public playground.', 403);
                }
                ++$items;
                if ($items > self::MAX_ITEMS) throw new FileError('Public demo file count limit exceeded.', 413);
                if ($file->isFile()) $bytes += $stat['size'];
                if ($bytes > self::MAX_BYTES) throw new FileError('Public demo storage limit exceeded.', 413);
            }
        }
        return ['bytes'=>$bytes, 'items'=>$items];
    }

    public static function assertBudget(string $storage, string $trash, int $extraBytes = 0, int $extraItems = 0): void
    {
        $u = self::usage($storage, $trash);
        if ($u['bytes'] + $extraBytes > self::MAX_BYTES || $u['items'] + $extraItems > self::MAX_ITEMS) {
            throw new FileError('Public demo quota reached. Delete files and start a new session.', 413);
        }
    }

    /** @return array{bytes:int,items:int} */
    private static function measure(Filesystem $fs, string $relative): array
    {
        $path = $fs->resolve($relative);
        if (is_file($path)) return ['bytes'=>(int) filesize($path), 'items'=>1];
        if (!is_dir($path)) throw new FileError('Demo operations only support ordinary files and folders.', 403);
        $bytes=0; $items=1;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $f) {
            if ($f->isLink() || (!$f->isDir() && !$f->isFile())) {
                throw new FileError('Unsupported file type in demo directory.', 403);
            }
            $st = @lstat($f->getPathname());
            if ($st === false || ($f->isFile() && $st['nlink'] > 1)) {
                throw new FileError('Hard links are not supported in demo transfers.', 403);
            }
            $items++;
            if ($f->isFile()) $bytes += $st['size'];
            if ($bytes > self::MAX_BYTES || $items > self::MAX_ITEMS) {
                throw new FileError('Demo source exceeds transfer limits.', 413);
            }
        }
        return ['bytes'=>$bytes,'items'=>$items];
    }

    /** @return array{bytes:int,items:int} */
    private static function archiveMeasure(Filesystem $fs, string $relative): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileError('PHP ZIP extension is not installed.', 501);
        }
        $zip = new \ZipArchive();
        if ($zip->open($fs->resolve($relative)) !== true) {
            throw new FileError('Cannot inspect ZIP archive.', 415);
        }
        try {
            $bytes = 0;
            $items = 1; // extraction destination
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) throw new FileError('Cannot inspect ZIP entry.', 415);
                $bytes += (int) ($stat['size'] ?? 0);
                $items += 2; // includes possible implicit parent directories
                if ($bytes > self::MAX_BYTES || $items > self::MAX_ITEMS) {
                    throw new FileError('ZIP contents exceed public demo limits.', 413);
                }
            }
            return ['bytes'=>$bytes,'items'=>$items];
        } finally {
            $zip->close();
        }
    }

    /** Application-level best-effort preflight; enforce HARD quotas at the OS/container level. */
    public static function preflight(Filesystem $fs, string $trash, string $action, array $body): void
    {
        $extraBytes = 0; $extraItems = 0;
        if ($action === 'create') {
            $extraItems = 1;
        } elseif ($action === 'save') {
            $content = $body['content'] ?? null;
            if (!is_string($content)) throw new FileError('Expected file content.', 400);
            $rel = $body['path'] ?? null;
            if (!is_string($rel)) throw new FileError('Expected file path.', 400);
            $path = $fs->resolve($rel);
            $extraBytes = strlen($content) - (int) (filesize($path) ?: 0);
        } elseif ($action === 'upload') {
            $extraBytes = (int) ($body['size'] ?? 0);
            $extraItems = 1;
        } elseif ($action === 'copy') {
            $m = self::measure($fs, (string) ($body['path'] ?? ''));
            $extraBytes = $m['bytes']; $extraItems = $m['items'];
        } elseif ($action === 'zip') {
            $m = self::measure($fs, (string) ($body['path'] ?? ''));
            $extraBytes = $m['bytes'] + $m['items'] * 256 + 65536;
            $extraItems = 1;
        } elseif ($action === 'extract') {
            $m = self::archiveMeasure($fs, (string) ($body['path'] ?? ''));
            $extraBytes = $m['bytes']; $extraItems = $m['items'];
        } elseif ($action === 'bulk-transfer' && ($body['mode'] ?? null) === 'copy') {
            $paths = $body['paths'] ?? null;
            if (!is_array($paths) || count($paths) > 25) throw new FileError('Invalid bulk selection.', 400);
            foreach ($paths as $relative) {
                if (!is_string($relative)) throw new FileError('Invalid bulk path.', 400);
                $m = self::measure($fs, $relative);
                $extraBytes += $m['bytes']; $extraItems += $m['items'];
            }
        }
        self::assertBudget($fs->root(), $trash, $extraBytes, $extraItems);
    }

    /** Prune expired session roots only. Run by the dedicated worker (no privilege escalation). */
    public static function prune(int $max = 30): int
    {
        $base = self::base();
        $lock = @fopen($base . '/.allocation.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new FileError('Playground cleanup lock is unavailable.', 503);
        }
        try {
            $removed = 0;
            foreach (scandir($base . '/sessions') ?: [] as $name) {
                if (!preg_match(self::ID_PATTERN, $name) || $removed >= $max) continue;
                $workspace = $base . '/sessions/' . $name;
                if (is_link($workspace) || !is_dir($workspace) || time() - (int) @filemtime($workspace) <= self::IDLE_SECONDS) continue;
                $trash = $base . '/trash/' . $name;
                if (is_dir($trash) && !is_link($trash)) self::deleteTree($trash);
                self::deleteTree($workspace);
                ++$removed;
            }
            return $removed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function deleteTree(string $dir): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) @rmdir($f->getPathname());
            else @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
