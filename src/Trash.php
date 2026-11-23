<?php
declare(strict_types=1);

namespace ForgeFileManager;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Private account-scoped recycle bin. Every move is a same-filesystem rename;
 * private trash storage is never exposed by the web server.
 *
 * This class does not attempt to defend against a hostile process that has the
 * SAME Unix identity and can concurrently mutate the tree. Deploy one isolated
 * PHP-FPM account identity per account and store the private trash outside its
 * configured public and customer filesystem roots.
 */
final class Trash
{
    private string $privateRoot;
    private string $storageRoot;

    public function __construct(string $storageRoot, string $privateRoot)
    {
        $a = realpath($storageRoot);
        $b = realpath($privateRoot);
        if ($a === false || $b === false || !is_dir($a) || !is_dir($b) || $a === '/' || $b === '/') {
            throw new FileError('Invalid storage or private trash root.', 503);
        }
        // Never permit overlap: hidden customer files in a normal directory are
        // NOT a secure substitute for private trash storage.
        if ($a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/')) {
            throw new FileError('Private trash must be outside the customer storage root.', 503);
        }
        $code = realpath(dirname(__DIR__));
        $web = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        foreach ([$code, $web] as $exposed) {
            if ($exposed && ($b === $exposed || str_starts_with($b, $exposed . '/'))) {
                throw new FileError('Private trash cannot be located in application or web directories.', 503);
            }
        }
        if ((@fileperms($b) & 0077) !== 0 || !is_writable($b)) {
            throw new FileError('Private trash must be owner-only and writable (chmod 700).', 503);
        }
        if (@fileowner($a) !== @fileowner($b)) {
            throw new FileError('Private trash must have the same owner as account storage.', 503);
        }
        if ((@stat($a)['dev'] ?? -1) !== (@stat($b)['dev'] ?? -2)) {
            throw new FileError('Private trash and account files must be on the same filesystem.', 503);
        }
        $this->storageRoot = $a;
        $this->privateRoot = $b;
    }

    /** @return array{id:string,original:string,deletedAt:int,type:string} */
    public function discard(Filesystem $fs, string $relative): array
    {
        $source = $fs->resolve($relative);
        if ($source === $fs->root()) {
            throw new FileError('Cannot recycle the account root.', 403);
        }
        if (!str_starts_with($source, $this->storageRoot . '/')) {
            throw new FileError('File is outside the authorized account.', 403);
        }
        if (is_link($source)) {
            throw new FileError('Symlinks cannot be moved into the recycle bin.', 403);
        }
        if (!is_writable(dirname($source))) {
            throw new FileError('Source directory is not writable.', 403);
        }
        if (!is_file($source) && !is_dir($source)) {
            throw new FileError('Only regular files and directories can be recycled.', 415);
        }
        if (is_dir($source)) {
            $this->checkTree($source);
        }
        $id = bin2hex(random_bytes(16));
        $entry = $this->privateRoot . '/' . $id;
        if (!@mkdir($entry, 0700)) {
            throw new FileError('Could not initialize private recycle-bin entry.', 500);
        }
        $original = substr($source, strlen($this->storageRoot) + 1);
        $meta = ['id' => $id, 'original' => $original, 'deletedAt' => time(), 'type' => is_dir($source) ? 'directory' : 'file'];
        $payload = $entry . '/data';
        try {
            if (!@rename($source, $payload)) {
                throw new FileError('Could not move file to recycle bin. No deletion occurred.', 409);
            }
            $json = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $descriptor = @fopen($entry . '/manifest.tmp', 'x');
            if (!$descriptor || fwrite($descriptor, $json) !== strlen($json) || !fflush($descriptor)) {
                if (is_resource($descriptor)) fclose($descriptor);
                throw new FileError('Could not write recycle-bin metadata.', 500);
            }
            fclose($descriptor);
            @chmod($entry . '/manifest.tmp', 0600);
            if (!@rename($entry . '/manifest.tmp', $entry . '/manifest.json')) {
                throw new FileError('Could not finalize recycle-bin metadata.', 500);
            }
            return $meta;
        } catch (\Throwable $e) {
            // If the journal write fails, recover the original content if
            // possible; otherwise preserve data in private trash for an admin.
            if (file_exists($payload)) {
                if (!@rename($payload, $source)) {
                    error_log('[ForgeFileManager] Manual recovery needed in recycle bin: ' . $id);
                }
            }
            @unlink($entry . '/manifest.tmp');
            if (!file_exists($payload)) @rmdir($entry);
            throw $e;
        }
    }

    /** @return array{items:list<array>,page:int,pages:int,pageSize:int,total:int} */
    public function listing(int $page = 1): array
    {
        if ($page < 1 || $page > 1000000) {
            throw new FileError('Invalid recycle-bin page.', 400);
        }
        $entries = [];
        $scanned = 0;
        foreach (new FilesystemIterator($this->privateRoot, FilesystemIterator::SKIP_DOTS) as $item) {
            if (++$scanned > 2000) {
                throw new FileError('Recycle-bin listing limit reached; administrative maintenance required.', 413);
            }
            if (!$item->isDir() || $item->isLink() || !preg_match('/^[a-f0-9]{32}$/D', $item->getFilename())) continue;
            try { $entries[] = $this->readMeta($item->getFilename()); }
            catch (FileError) { continue; }
        }
        usort($entries, static fn(array $x, array $y): int => ($y['deletedAt'] <=> $x['deletedAt']) ?: strcmp($y['id'], $x['id']));
        $total = count($entries);
        $pages = max(1, (int) ceil($total / 25));
        return ['items' => array_slice($entries, (min($page, $pages) - 1) * 25, 25), 'page' => min($page, $pages), 'pages' => $pages, 'pageSize' => 25, 'total' => $total];
    }

    /** @return array{path:string,restored:bool} */
    public function restore(Filesystem $fs, string $id): array
    {
        $meta = $this->readMeta($id);
        $original = $meta['original'];
        $destination = $fs->resolve($original, false);
        if (file_exists($destination) || is_link($destination)) {
            throw new FileError('Restore destination exists; no file was overwritten.', 409);
        }
        if (!is_writable(dirname($destination))) {
            throw new FileError('Original parent directory is not writable.', 403);
        }
        $payload = $this->privateRoot . '/' . $id . '/data';
        if (is_dir($payload)) {
            $this->checkTree($payload);
        }
        if (!@rename($payload, $destination)) {
            throw new FileError('Restore failed. Recycle-bin entry remains available.', 409);
        }
        $base = $this->privateRoot . '/' . $id;
        if (!@unlink($base . '/manifest.json') || !@rmdir($base)) {
            error_log('[ForgeFileManager] Restored file but failed to clean recycle-bin entry: ' . $id);
        }
        return ['path' => $original, 'restored' => true];
    }

    /** @return array{id:string,original:string,deletedAt:int,type:string} */
    private function readMeta(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new FileError('Invalid recycle-bin ID.', 400);
        $base = $this->privateRoot . '/' . $id;
        if (!is_dir($base) || is_link($base) || is_link($base . '/manifest.json') || is_link($base . '/data')) {
            throw new FileError('Recycle-bin entry unavailable.', 404);
        }
        if (!is_file($base . '/manifest.json')) throw new FileError('Recycle-bin manifest unavailable.', 404);
        $raw = @file_get_contents($base . '/manifest.json', false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) throw new FileError('Invalid recycle-bin manifest.', 409);
        $meta = json_decode($raw, true);
        if (!is_array($meta) || ($meta['id'] ?? '') !== $id || !is_string($meta['original'] ?? null) ||
            !is_int($meta['deletedAt'] ?? null) || !in_array($meta['type'] ?? '', ['file','directory'], true)) {
            throw new FileError('Corrupted recycle-bin manifest.', 409);
        }
        // The manifest is written internally; nonetheless independently check
        // its path against the same traversal/symlink rules as normal actions.
        $segments = explode('/', $meta['original']);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255 || str_contains($segment, '\\') || preg_match('/[\x00-\x1f\x7f]/', $segment)) {
                throw new FileError('Unsafe recycle-bin path.', 409);
            }
        }
        if (!is_file($base . '/data') && !is_dir($base . '/data')) {
            throw new FileError('Recycle-bin content is unavailable.', 404);
        }
        return $meta;
    }

    /** Scan before moving a directory; never accept symlinks or huge trees. */
    private function checkTree(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $count = 0;
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new FileError('Directory contains a symbolic link and cannot be recycled.', 403);
            if (++$count > 20000 || $iterator->getDepth() > 32) {
                throw new FileError('Directory exceeds recycle-bin safety limits (20,000 entries / 32 levels).', 413);
            }
        }
    }
}
