<?php
declare(strict_types=1);

namespace ForgeFileManager;

require_once __DIR__ . '/DirectoryPager.php';

use DirectoryIterator;
use RuntimeException;

/**
 * Account-scoped filesystem operations. This is not a substitute for OS-level
 * tenant isolation: the PHP worker MUST run as the authorized hosting user.
 * Filesystem path validation is not race-free against hostile local processes.
 */
final class Filesystem
{
    private string $root;
    private int $maxEditBytes;
    private int $maxEntries;
    private int $maxUploadBytes;
    private int $maxListScan;

    public function __construct(string $root, int $maxEditBytes = 1048576, int $maxEntries = 2000, int $maxUploadBytes = 104857600, int $maxListScan = 50000)
    {
        $real = realpath($root);
        if ($real === false || $real === DIRECTORY_SEPARATOR || !is_dir($real)) {
            throw new RuntimeException('Configured storage root is missing or invalid.');
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
        $this->maxEditBytes = max(1024, $maxEditBytes);
        $this->maxEntries = max(10, $maxEntries);
        $this->maxUploadBytes = max(1, $maxUploadBytes);
        $this->maxListScan = max(25, min(200000, $maxListScan));
    }

    public function maxUploadBytes(): int
    {
        return $this->maxUploadBytes;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<string> */
    private function segments(string $relative): array
    {
        if ($relative === '' || $relative === '.') {
            return [];
        }
        if (str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('/[\x00-\x1f\x7f]/', $relative)) {
            throw new FileError('Invalid relative path.', 400);
        }
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            $this->validName($part);
        }
        return $parts;
    }

    private function validName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\') || strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new FileError('Invalid file or folder name.', 400);
        }
    }

    private function relative(string $path): string
    {
        return implode('/', $this->segments($path));
    }

    /**
     * Resolve every component independently and reject symbolic links,
     * including dangling symlinks. Missing *last* component is optional.
     */
    public function resolve(string $relative, bool $mustExist = true): string
    {
        $parts = $this->segments($relative);
        $candidate = $this->root;
        foreach ($parts as $index => $part) {
            $candidate .= DIRECTORY_SEPARATOR . $part;
            if (is_link($candidate)) {
                throw new FileError('Symbolic links cannot be accessed through this manager.', 403);
            }
            if (!file_exists($candidate)) {
                if (!$mustExist && $index === count($parts) - 1) {
                    return $candidate;
                }
                throw new FileError('Path does not exist.', 404);
            }
            $stat = @lstat($candidate);
            // A hard link cannot reveal whether its other name is outside the
            // authorized root. Refuse linked regular files rather than guess.
            if ($stat !== false && (($stat['mode'] & 0170000) === 0100000) && ($stat['nlink'] ?? 1) > 1) {
                throw new FileError('Hard-linked files are not supported within a managed account.', 403);
            }
            $real = realpath($candidate);
            if ($real === false || ($real !== $this->root && !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR))) {
                throw new FileError('Access outside the storage root is forbidden.', 403);
            }
            $candidate = $real;
        }
        return $candidate;
    }

    private function parentOf(string $relative): array
    {
        $segments = $this->segments($relative);
        if ($segments === []) {
            throw new FileError('Cannot modify the hosting root.', 403);
        }
        $name = array_pop($segments);
        $parentRel = implode('/', $segments);
        $parent = $this->resolve($parentRel);
        if (!is_dir($parent)) {
            throw new FileError('Parent is not a directory.', 400);
        }
        return [$parent, $parentRel, $name];
    }

    /** @return array<string, mixed> */
    private function metadata(string $absolute, string $relative, string $name): array
    {
        $link = is_link($absolute);
        $dir = !$link && is_dir($absolute);
        $stat = @lstat($absolute);
        return [
            'name' => $name,
            'path' => $relative,
            'type' => $link ? 'symlink' : ($dir ? 'directory' : 'file'),
            'size' => $dir || $link ? null : (($stat['size'] ?? null) ?: 0),
            'modified' => $stat['mtime'] ?? null,
            'modifiedIso' => isset($stat['mtime']) ? gmdate('Y-m-d\TH:i:s\Z', (int) $stat['mtime']) : null,
            'mode' => sprintf('%03o', ($stat['mode'] ?? 0) & 0777),
            'readable' => !$link && is_readable($absolute),
            'writable' => !$link && is_writable($absolute),
            'extension' => $dir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        ];
    }

    /**
     * Scan the directory within an explicit resource budget, sort/filter across
     * the WHOLE folder, then return only 25 detailed metadata records.
     * Exceeding the scan limit is an error, never a misleading partial total.
     * @return array<string, mixed>
     */
    public function listing(string $relative = '', int $page = 1, string $search = '', string $sort = 'name', bool $showHidden = false): array
    {
        $relative = $this->relative($relative);
        $folder = $this->resolve($relative);
        if (!is_dir($folder) || !is_readable($folder)) {
            throw new FileError('Directory is not readable.', 403);
        }
        $candidates = [];
        foreach (new DirectoryIterator($folder) as $entry) {
            if ($entry->isDot()) continue;
            if (count($candidates) >= $this->maxListScan) {
                throw new FileError('Directory scan limit reached. Increase FFM_LIST_SCAN_LIMIT for this account.', 413);
            }
            $absolute = $entry->getPathname();
            $stat = @lstat($absolute);
            // lstat avoids following symlinks outside the account boundary.
            if ($stat === false) continue; // concurrent deletion
            $dir = !is_link($absolute) && is_dir($absolute);
            $candidates[] = [
                'name' => $entry->getFilename(),
                'type' => is_link($absolute) ? 'symlink' : ($dir ? 'directory' : 'file'),
                'size' => $dir ? null : (int) $stat['size'],
                'modified' => (int) $stat['mtime'],
            ];
        }
        $paging = DirectoryPager::paginate($candidates, $page, $search, $sort, $showHidden);
        $items = [];
        foreach ($paging['items'] as $row) {
            $name = $row['name'];
            $relativePath = $relative === '' ? $name : $relative . '/' . $name;
            $absolute = $folder . DIRECTORY_SEPARATOR . $name;
            if (@lstat($absolute) === false) continue; // concurrent deletion
            $items[] = $this->metadata($absolute, $relativePath, $name);
        }
        $diskTotal = @disk_total_space($this->root);
        $diskFree = @disk_free_space($this->root);
        return [
            'path' => $relative,
            'items' => $items,
            'page' => $paging['page'],
            'pages' => $paging['pages'],
            'pageSize' => $paging['pageSize'],
            'total' => $paging['total'],
            'hasNext' => $paging['hasNext'],
            'truncated' => false,
            'scanLimit' => $this->maxListScan,
            'disk' => [
                'free' => $diskFree === false ? null : $diskFree,
                'total' => $diskTotal === false ? null : $diskTotal,
                'isAccountQuota' => false,
            ],
        ];
    }

    public function create(string $directory, string $name, string $kind): array
    {
        $this->validName($name);
        $parent = $this->resolve($directory);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new FileError('Directory is not writable.', 403);
        }
        $relative = $this->relative($directory);
        $target = $this->resolve(($relative ? $relative . '/' : '') . $name, false);
        if ($kind === 'directory') {
            if (!@mkdir($target, 0755)) {
                throw new FileError('Failed to create directory.', 409);
            }
        } elseif ($kind === 'file') {
            $handle = @fopen($target, 'x');
            if ($handle === false) {
                throw new FileError('Failed to create file.', 409);
            }
            fclose($handle);
        } else {
            throw new FileError('Unsupported create type.', 400);
        }
        return ['path' => ($relative ? $relative . '/' : '') . $name];
    }

    public function renamePath(string $relative, string $newName): array
    {
        $this->validName($newName);
        [$parent, $parentRel, $oldName] = $this->parentOf($relative);
        if ($oldName === $newName) {
            throw new FileError('Name is unchanged.', 400);
        }
        $source = $this->resolve($relative);
        $destRel = ($parentRel ? $parentRel . '/' : '') . $newName;
        $target = $this->resolve($destRel, false);
        if (!is_writable($parent) || file_exists($target) || is_link($target) || !@rename($source, $target)) {
            throw new FileError('Rename failed or destination exists.', 409);
        }
        return ['path' => $destRel];
    }

    public function chmodPath(string $relative, string $mode, bool $allowWorldWritable = false): array
    {
        if (!preg_match('/^[0-7]{3}$/D', $mode)) {
            throw new FileError('CHMOD must be a three-digit octal value (e.g. 644).', 400);
        }
        $numeric = octdec($mode);
        if (!$allowWorldWritable && ($numeric & 0002)) {
            throw new FileError('World-writable modes are disabled by server policy.', 403);
        }
        $target = $this->resolve($relative);
        if ($target === $this->root) {
            throw new FileError('Changing root permissions is blocked.', 403);
        }
        $prior = @lstat($target);
        if (!is_array($prior) || (!is_file($target) && !is_dir($target))) {
            throw new FileError('CHMOD only supports regular files and directories.', 403);
        }
        if (!@chmod($target, $numeric)) {
            throw new FileError('Permission change failed (check hosting user ownership).', 403);
        }
        clearstatcache(true, $target);
        $after = @lstat($target);
        if (!is_array($after) || $prior['dev'] !== $after['dev'] || $prior['ino'] !== $after['ino']) {
            throw new FileError('Target changed during CHMOD; verify its permissions.', 409);
        }
        if (($after['mode'] & 0777) !== $numeric) {
            throw new FileError('Requested permissions were not applied.', 409);
        }
        return [
            'path' => $this->relative($relative),
            'mode' => sprintf('%03o', $after['mode'] & 0777),
            'modified' => $after['mtime'], // CHMOD changes ctime, not mtime.
        ];
    }

    /**
     * Verified read handle: refuse symlink/hardlink substitution between
     * path validation and fopen. This is still not a full dirfd/openat2
     * defense against hostile same-UID writers; OS account isolation remains
     * mandatory.
     * @return array{resource,string,int}
     */
    public function openFileForRead(string $relative): array
    {
        $source = $this->resolve($relative);
        if (!is_file($source) || !is_readable($source)) {
            throw new FileError('File cannot be read.', 403);
        }
        $handle = @fopen($source, 'rb');
        if (!is_resource($handle)) throw new FileError('Could not open file.', 403);
        $open = fstat($handle);
        clearstatcache(true, $source);
        $link = @lstat($source);
        if (!is_array($open) || !is_array($link) || is_link($source) ||
            ($open['mode'] & 0170000) !== 0100000 || ($open['nlink'] ?? 1) !== 1 ||
            $link['dev'] !== $open['dev'] || $link['ino'] !== $open['ino']) {
            fclose($handle);
            throw new FileError('File changed or is not an authorized regular file.', 403);
        }
        return [$handle, basename($source), (int) $open['size']];
    }

    public function readText(string $relative): array
    {
        [$handle, , $size] = $this->openFileForRead($relative);
        try {
            if ($size > $this->maxEditBytes) {
                throw new FileError('File exceeds the editor size limit.', 413);
            }
            $before = fstat($handle);
            $content = stream_get_contents($handle, $this->maxEditBytes + 1);
            $after = fstat($handle);
            if ($content === false || strlen($content) > $this->maxEditBytes ||
                strlen($content) !== $size || !is_array($before) || !is_array($after) ||
                $before['mtime'] !== $after['mtime'] || $before['ctime'] !== $after['ctime'] ||
                str_contains($content, "\0") || !preg_match('//u', $content)) {
                throw new FileError('Editor supports unchanged, bounded UTF-8 files only.', 415);
            }
            return [
                'path' => $this->relative($relative), 'content' => $content,
                'hash' => hash('sha256', $content), 'maxBytes' => $this->maxEditBytes,
                'modified' => $after['mtime'],
            ];
        } finally {
            fclose($handle);
        }
    }

    /** Optimistic concurrency + advisory lock; does not replace OS isolation. */
    public function saveText(string $relative, string $content, string $expectedHash): array
    {
        if (strlen($content) > $this->maxEditBytes || str_contains($content, "\0") || !preg_match('//u', $content)) {
            throw new FileError('Invalid UTF-8 text or editor size limit exceeded.', 413);
        }
        if (!preg_match('/^[0-9a-f]{64}$/D', $expectedHash)) {
            throw new FileError('A valid file revision hash is required.', 400);
        }
        $source = $this->resolve($relative);
        $parent = dirname($source);
        if (!is_file($source) || !is_writable($source) || !is_writable($parent)) {
            throw new FileError('File and its directory must be writable.', 403);
        }
        $in = @fopen($source, 'rb');
        if (!is_resource($in)) throw new FileError('Could not open file for editing.', 403);
        $locked = false;
        $temp = null;
        try {
            if (!flock($in, LOCK_EX)) throw new FileError('Could not lock file for editing.', 409);
            $locked = true;
            $beforeStat = fstat($in);
            if (!is_array($beforeStat) || ($beforeStat['mode'] & 0170000) !== 0100000 || ($beforeStat['nlink'] ?? 1) !== 1 ||
                $beforeStat['size'] > $this->maxEditBytes) {
                throw new FileError('File is not a safe, bounded regular file.', 403);
            }
            clearstatcache(true, $source);
            $pathStat = @lstat($source);
            if (!is_array($pathStat) || is_link($source) || $beforeStat['dev'] !== $pathStat['dev'] ||
                $beforeStat['ino'] !== $pathStat['ino']) {
                throw new FileError('File changed during opening.', 409);
            }
            $before = stream_get_contents($in, $this->maxEditBytes + 1);
            if ($before === false || strlen($before) > $this->maxEditBytes ||
                !hash_equals(hash('sha256', $before), $expectedHash)) {
                throw new FileError('File changed since it was opened. Reload before saving.', 409);
            }
            $temp = $parent . '/.ffm-tmp-' . bin2hex(random_bytes(16));
            // Restrict new temporary files from creation, including under a
            // directory whose static files may be served over HTTP.
            $priorUmask = umask(0077);
            try { $out = @fopen($temp, 'xb'); }
            finally { umask($priorUmask); }
            if (!is_resource($out)) throw new FileError('Could not allocate a temporary file.', 500);
            try {
                $length = strlen($content);
                for ($offset = 0; $offset < $length;) {
                    $n = fwrite($out, substr($content, $offset));
                    if ($n === false || $n === 0) throw new FileError('Incomplete write.', 500);
                    $offset += $n;
                }
                if (!fflush($out)) throw new FileError('Could not flush edited file.', 500);
                if (function_exists('fsync') && !fsync($out)) {
                    throw new FileError('Could not synchronize edited file.', 500);
                }
            } finally {
                fclose($out);
            }
            if (!@chmod($temp, $beforeStat['mode'] & 0777)) {
                throw new FileError('Could not preserve original file permissions.', 500);
            }
            // Check the current pathname and inode before atomic publication.
            clearstatcache(true, $source);
            $this->resolve($relative);
            $now = @lstat($source);
            if (!is_array($now) || $now['dev'] !== $beforeStat['dev'] || $now['ino'] !== $beforeStat['ino'] ||
                !hash_equals($expectedHash, (string) @hash_file('sha256', $source))) {
                throw new FileError('File changed during save.', 409);
            }
            if (!@rename($temp, $source)) throw new FileError('Could not replace the file.', 500);
            $temp = null;
        } finally {
            if ($temp !== null) @unlink($temp);
            if ($locked) flock($in, LOCK_UN);
            fclose($in);
        }
        clearstatcache(true, $source);
        return [
            'path' => $this->relative($relative),
            'hash' => hash('sha256', $content),
            'modified' => @filemtime($source) ?: null,
            'mode' => sprintf('%03o', (int) (@fileperms($source) ?: 0) & 0777),
        ];
    }

    public function remove(string $relative): array
    {
        [$parent] = $this->parentOf($relative);
        $absolute = $this->resolve($relative);
        if (!is_writable($parent)) {
            throw new FileError('Parent directory is not writable.', 403);
        }
        // The first release intentionally does not recursively destroy directories.
        $ok = is_dir($absolute) ? @rmdir($absolute) : @unlink($absolute);
        if (!$ok) {
            throw new FileError('Delete failed. Non-empty folders cannot be deleted in this version.', 409);
        }
        return ['deleted' => $this->relative($relative)];
    }

    /**
     * Inspect a tree before copying. Refuse links and unsupported special files,
     * cap size, entry count and depth. This is a bounded synchronous operation,
     * not a race-free substitute for per-account OS isolation.
     *
     * @param array{entries:int,bytes:int} $budget
     */
    private function preflightCopy(string $path, string $relative, array &$budget, int $depth = 0): void
    {
        if ($depth > 20 || ++$budget['entries'] > min(2000, $this->maxEntries)) {
            throw new FileError('Folder copy exceeds the 2000 entry or 20 level limit.', 413);
        }
        if (is_link($path)) {
            throw new FileError('Folder copy cannot contain symbolic links.', 403);
        }
        // Revalidate each component, not just the outer folder.
        $this->resolve($relative);
        if (is_file($path)) {
            if (!is_readable($path)) {
                throw new FileError('One of the source files is unreadable.', 403);
            }
            $size = filesize($path);
            if ($size === false || ($budget['bytes'] += $size) > 100 * 1024 * 1024) {
                throw new FileError('Folder copy exceeds the 100 MB content limit.', 413);
            }
            return;
        }
        if (!is_dir($path) || !is_readable($path)) {
            throw new FileError('Folder copy requires regular files and readable folders.', 403);
        }
        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot()) continue;
            $name = $item->getFilename();
            $this->preflightCopy($item->getPathname(), $relative . '/' . $name, $budget, $depth + 1);
        }
    }

    /** Copy only inspected regular files and directories into a private staging directory. */
    private function copyTree(string $source, string $dest, array &$budget, int $depth = 0): void
    {
        if ($depth > 20 || ++$budget['entries'] > min(2000, $this->maxEntries) || is_link($source)) {
            throw new FileError('Unsafe folder entry encountered during copy.', 403);
        }
        if (is_dir($source)) {
            if (!@mkdir($dest, 0700)) {
                throw new FileError('Could not create destination directory.', 500);
            }
            foreach (new DirectoryIterator($source) as $item) {
                if (!$item->isDot()) {
                    $this->copyTree($item->getPathname(), $dest . '/' . $item->getFilename(), $budget, $depth + 1);
                }
            }
            @chmod($dest, (fileperms($source) & 0755) ?: 0700);
            return;
        }
        if (!is_file($source) || !is_readable($source)) {
            throw new FileError('Unsafe or unreadable file encountered during copy.', 403);
        }
        $size = filesize($source);
        if ($size === false || ($budget['bytes'] += $size) > 100 * 1024 * 1024) {
            throw new FileError('Copy exceeds the 100 MB content limit.', 413);
        }
        $from = @fopen($source, 'rb');
        $to = @fopen($dest, 'xb');
        if ($from === false || $to === false) {
            if (is_resource($from)) fclose($from);
            if (is_resource($to)) fclose($to);
            throw new FileError('Could not copy a source file.', 500);
        }
        try {
            if (stream_copy_to_stream($from, $to, $size + 1) !== $size) {
                throw new FileError('Source file changed or copy was interrupted.', 409);
            }
            fflush($to);
        } finally {
            fclose($from);
            fclose($to);
        }
        @chmod($dest, fileperms($source) & 0644);
    }

    /** Remove only a temporary staging tree, without following symlinks. */
    private function cleanupCopyStage(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        foreach (new DirectoryIterator($path) as $item) {
            if (!$item->isDot()) $this->cleanupCopyStage($item->getPathname());
        }
        @rmdir($path);
    }

    /** @return array{path:string} */
    public function transfer(string $relative, string $directory, string $action): array
    {
        [$sourceParent] = $this->parentOf($relative);
        $source = $this->resolve($relative);
        $parentRel = $this->relative($directory);
        $targetDir = $this->resolve($parentRel);
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            throw new FileError('Target directory is not writable.', 403);
        }
        if ($action !== 'copy' && $action !== 'move') {
            throw new FileError('Unsupported transfer operation.', 400);
        }
        if (is_dir($source) && ($targetDir === $source || str_starts_with($targetDir, $source . '/'))) {
            throw new FileError('Cannot copy or move a directory into itself or a descendant.', 400);
        }
        $name = basename($source);
        $newName = $name;
        if ($action === 'copy') {
            $ext = is_file($source) ? pathinfo($name, PATHINFO_EXTENSION) : '';
            $stem = $ext === '' ? $name : substr($name, 0, -strlen($ext) - 1);
            for ($i = 0; $i < 100; $i++) {
                $suffix = $i === 0 ? '' : ($i === 1 ? ' (copy)' : ' (copy ' . $i . ')');
                $candidate = $stem . $suffix . ($ext === '' ? '' : '.' . $ext);
                if (strlen($candidate) > 255) throw new FileError('Filename is too long to duplicate.', 400);
                if (!file_exists($targetDir . '/' . $candidate) && !is_link($targetDir . '/' . $candidate)) {
                    $newName = $candidate;
                    break;
                }
                if ($i === 99) throw new FileError('Too many duplicate names in the destination.', 409);
            }
        }
        $relativeTarget = ($parentRel ? $parentRel . '/' : '') . $newName;
        $target = $this->resolve($relativeTarget, false);
        if ($action === 'move') {
            if (!is_writable($sourceParent) || $source === $target || file_exists($target) || is_link($target) || !@rename($source, $target)) {
                throw new FileError('Move failed or destination exists.', 409);
            }
            return ['path' => $relativeTarget];
        }
        $budget = ['entries' => 0, 'bytes' => 0];
        $this->preflightCopy($source, $this->relative($relative), $budget);
        $stage = $targetDir . '/.ffm-copy-' . bin2hex(random_bytes(12));
        try {
            $actual = ['entries' => 0, 'bytes' => 0];
            $this->copyTree($source, $stage, $actual);
            if (file_exists($target) || is_link($target) || !@rename($stage, $target)) {
                throw new FileError('Copy target already exists or could not be published.', 409);
            }
        } finally {
            $this->cleanupCopyStage($stage);
        }
        return ['path' => $relativeTarget, 'filesAndFolders' => $budget['entries'], 'bytes' => $budget['bytes']];
    }

    /**
     * Bounded checksum verification for individual files, never directory trees.
     * SHA-256 may take time on large files: limit 100 MB in the synchronous API.
     */
    public function checksum(string $relative): array
    {
        [$handle, , $size] = $this->openFileForRead($relative);
        try {
            if ($size > 100 * 1024 * 1024) {
                throw new FileError('Checksums currently support files up to 100 MiB.', 413);
            }
            $before = fstat($handle);
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $handle, $size + 1);
            $after = fstat($handle);
            if ($read !== $size || !is_array($before) || !is_array($after) ||
                $before['mtime'] !== $after['mtime'] || $before['ctime'] !== $after['ctime'] ||
                $before['size'] !== $after['size']) {
                throw new FileError('File changed during checksum calculation.', 409);
            }
            return ['path' => $this->relative($relative), 'algorithm' => 'SHA-256',
                    'checksum' => hash_final($hash), 'size' => $size];
        } finally {
            fclose($handle);
        }
    }

    public function upload(string $directory, array $file): array
    {
        $name = (string) ($file['name'] ?? '');
        $this->validName($name);
        if (in_array($file['error'] ?? UPLOAD_ERR_NO_FILE, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new FileError('Upload exceeds server size limits.', 413);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new FileError('Upload was not completed.', 400);
        }
        $actualSize = @filesize((string) $file['tmp_name']);
        if ($actualSize === false || $actualSize > $this->maxUploadBytes) {
            throw new FileError('Upload exceeds the configured file size limit.', 413);
        }
        $parent = $this->resolve($directory);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new FileError('Target directory is not writable.', 403);
        }
        $relative = $this->relative($directory);
        $relativeTarget = ($relative ? $relative . '/' : '') . $name;
        $target = $this->resolve($relativeTarget, false);
        if (file_exists($target) || is_link($target)) {
            throw new FileError('Upload target already exists.', 409);
        }
        if (!@move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new FileError('Could not store uploaded file.', 500);
        }
        @chmod($target, 0644);
        return ['path' => $relativeTarget, 'size' => filesize($target)];
    }

    /** Create a ZIP in a temporary sibling, then publish only if free. */
    public function zip(string $relative): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileError('PHP ZIP extension is not installed.', 501);
        }
        [$parent, $parentRel, $name] = $this->parentOf($relative);
        $sourceRel = $this->relative($relative);
        $source = $this->resolve($sourceRel);
        if (!is_file($source) && !is_dir($source)) {
            throw new FileError('ZIP supports only regular files and directories.', 403);
        }
        if (!is_readable($source) || !is_writable($parent)) {
            throw new FileError('ZIP source must be readable and target directory writable.', 403);
        }
        $targetRel = ($parentRel ? $parentRel . '/' : '') . $name . '.zip';
        $target = $this->resolve($targetRel, false);
        if (file_exists($target) || is_link($target)) throw new FileError('Archive already exists.', 409);
        $budget = ['entries' => 0, 'bytes' => 0];
        $this->preflightCopy($source, $sourceRel, $budget);
        $stage = $parent . '/.ffm-zip-' . bin2hex(random_bytes(16)) . '.zip';
        $archive = new \ZipArchive();
        $priorUmask = umask(0077);
        try { $openedOk = $archive->open($stage, \ZipArchive::CREATE | \ZipArchive::EXCL); }
        finally { umask($priorUmask); }
        if ($openedOk !== true) {
            @unlink($stage);
            throw new FileError('Could not initialize private ZIP archive.', 500);
        }
        $opened = true;
        try {
            if (is_file($source)) {
                if (!$archive->addFile($source, $name)) throw new FileError('Could not add file to ZIP.', 500);
            } else {
                if (!$archive->addEmptyDir($name)) throw new FileError('Could not add directory to ZIP.', 500);
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                $entries = 0;
                $bytes = 0;
                foreach ($iter as $entry) {
                    if (++$entries > min($this->maxEntries, 2000) || $iter->getDepth() > 20 || $entry->isLink()) {
                        throw new FileError('Archive entry count, depth or link limit exceeded.', 413);
                    }
                    $path = $entry->getPathname();
                    $this->resolve($sourceRel . substr($path, strlen($source)));
                    $local = str_replace(DIRECTORY_SEPARATOR, '/', $name . substr($path, strlen($source)));
                    if ($entry->isDir()) {
                        if (!$archive->addEmptyDir($local)) throw new FileError('Could not add ZIP directory.', 500);
                    } elseif ($entry->isFile()) {
                        $size = @filesize($path);
                        if ($size === false || ($bytes += $size) > 100 * 1024 * 1024) {
                            throw new FileError('Archive content exceeds 100 MiB.', 413);
                        }
                        if (!$archive->addFile($path, $local)) throw new FileError('Could not add ZIP file.', 500);
                    } else {
                        throw new FileError('Archive contains an unsupported file type.', 403);
                    }
                }
            }
            if (!$archive->close()) {
                $opened = false;
                throw new FileError('Could not finalize ZIP archive.', 500);
            }
            $opened = false;
            clearstatcache(true, $stage);
            if (!is_file($stage) || @filesize($stage) > 110 * 1024 * 1024 || !@chmod($stage, 0600)) {
                throw new FileError('ZIP archive is invalid or exceeds storage limits.', 413);
            }
            // This check is not a substitute for account-level OS isolation.
            if (file_exists($target) || is_link($target) || !@rename($stage, $target)) {
                throw new FileError('ZIP destination was occupied during publication.', 409);
            }
            $stage = '';
            clearstatcache(true, $target);
            return [
                'path' => $targetRel, 'entries' => $budget['entries'],
                'bytes' => $budget['bytes'], 'modified' => @filemtime($target) ?: null,
            ];
        } finally {
            if ($opened) @$archive->close();
            if ($stage !== '') @unlink($stage);
        }
    }

    public function downloadPath(string $relative): array
    {
        $path = $this->resolve($relative);
        if (!is_file($path) || !is_readable($path)) {
            throw new FileError('Only readable regular files can be downloaded.', 403);
        }
        return [$path, basename($path)];
    }
}

final class FileError extends RuntimeException
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message, $status);
    }
}
