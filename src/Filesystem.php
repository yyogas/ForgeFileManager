<?php
declare(strict_types=1);

namespace ForgeFileManager;

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

    public function __construct(string $root, int $maxEditBytes = 1048576, int $maxEntries = 2000, int $maxUploadBytes = 104857600)
    {
        $real = realpath($root);
        if ($real === false || $real === DIRECTORY_SEPARATOR || !is_dir($real)) {
            throw new RuntimeException('Configured storage root is missing or invalid.');
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
        $this->maxEditBytes = max(1024, $maxEditBytes);
        $this->maxEntries = max(10, $maxEntries);
        $this->maxUploadBytes = max(1, $maxUploadBytes);
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
            'mode' => sprintf('%03o', ($stat['mode'] ?? 0) & 0777),
            'readable' => !$link && is_readable($absolute),
            'writable' => !$link && is_writable($absolute),
            'extension' => $dir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        ];
    }

    /** @return array<string, mixed> */
    public function listing(string $relative = ''): array
    {
        $relative = $this->relative($relative);
        $folder = $this->resolve($relative);
        if (!is_dir($folder) || !is_readable($folder)) {
            throw new FileError('Directory is not readable.', 403);
        }
        $items = [];
        $truncated = false;
        foreach (new DirectoryIterator($folder) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (count($items) >= $this->maxEntries) {
                $truncated = true;
                break;
            }
            $name = $entry->getFilename();
            $path = $relative === '' ? $name : $relative . '/' . $name;
            $items[] = $this->metadata($entry->getPathname(), $path, $name);
        }
        $diskTotal = @disk_total_space($this->root);
        $diskFree = @disk_free_space($this->root);
        return [
            'path' => $relative,
            'items' => $items,
            'truncated' => $truncated,
            'maxEntries' => $this->maxEntries,
            'disk' => ['free' => $diskFree === false ? null : $diskFree, 'total' => $diskTotal === false ? null : $diskTotal, 'isAccountQuota' => false],
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
        if (!@chmod($target, $numeric)) {
            throw new FileError('Permission change failed (check hosting user ownership).', 403);
        }
        // PHP versions differ in how quickly cached stat data is invalidated.
        clearstatcache(true, $target);
        return ['path' => $this->relative($relative), 'mode' => sprintf('%03o', fileperms($target) & 0777)];
    }

    public function readText(string $relative): array
    {
        $source = $this->resolve($relative);
        if (!is_file($source) || !is_readable($source)) {
            throw new FileError('File cannot be read.', 403);
        }
        $size = filesize($source);
        if ($size === false || $size > $this->maxEditBytes) {
            throw new FileError('File exceeds the editor size limit.', 413);
        }
        $content = @file_get_contents($source);
        if ($content === false || str_contains($content, "\0") || !preg_match('//u', $content)) {
            throw new FileError('Editor supports UTF-8 text files only.', 415);
        }
        return ['path' => $this->relative($relative), 'content' => $content, 'hash' => hash('sha256', $content), 'maxBytes' => $this->maxEditBytes];
    }

    public function saveText(string $relative, string $content, string $expectedHash): array
    {
        if (strlen($content) > $this->maxEditBytes || str_contains($content, "\0") || !preg_match('//u', $content)) {
            throw new FileError('Invalid UTF-8 text or editor size limit exceeded.', 413);
        }
        $source = $this->resolve($relative);
        if (!is_file($source) || !is_writable($source)) {
            throw new FileError('File is not writable.', 403);
        }
        $before = @file_get_contents($source);
        if ($before === false || !hash_equals(hash('sha256', $before), $expectedHash)) {
            throw new FileError('File changed since it was opened. Reload before saving.', 409);
        }
        $dir = dirname($source);
        $temp = $dir . '/.ffm-tmp-' . bin2hex(random_bytes(12));
        $handle = @fopen($temp, 'x+b');
        if ($handle === false) {
            throw new FileError('Could not allocate a temporary file.', 500);
        }
        try {
            $written = fwrite($handle, $content);
            if ($written !== strlen($content)) {
                throw new FileError('Incomplete write.', 500);
            }
            fflush($handle);
            fclose($handle);
            $handle = null;
            @chmod($temp, fileperms($source) & 0777);
            // Recheck, but concurrent external filesystem writers remain a race.
            if (!hash_equals(hash('sha256', (string) @file_get_contents($source)), $expectedHash)) {
                throw new FileError('File changed during save.', 409);
            }
            if (!@rename($temp, $source)) {
                throw new FileError('Could not replace the file.', 500);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temp)) {
                @unlink($temp);
            }
        }
        return ['path' => $this->relative($relative), 'hash' => hash('sha256', $content)];
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
    private function copyTree(string $source, string $dest, int $depth = 0): void
    {
        if ($depth > 20 || is_link($source)) {
            throw new FileError('Unsafe folder entry encountered during copy.', 403);
        }
        if (is_dir($source)) {
            if (!@mkdir($dest, 0700)) {
                throw new FileError('Could not create destination directory.', 500);
            }
            foreach (new DirectoryIterator($source) as $item) {
                if (!$item->isDot()) {
                    $this->copyTree($item->getPathname(), $dest . '/' . $item->getFilename(), $depth + 1);
                }
            }
            @chmod($dest, (fileperms($source) & 0755) ?: 0700);
            return;
        }
        if (!is_file($source) || !is_readable($source)) {
            throw new FileError('Unsafe or unreadable file encountered during copy.', 403);
        }
        $from = @fopen($source, 'rb');
        $to = @fopen($dest, 'xb');
        if ($from === false || $to === false) {
            if (is_resource($from)) fclose($from);
            if (is_resource($to)) fclose($to);
            throw new FileError('Could not copy a source file.', 500);
        }
        try {
            $size = filesize($source);
            if ($size === false || $size > 100 * 1024 * 1024 || stream_copy_to_stream($from, $to, $size + 1) !== $size) {
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
            $this->copyTree($source, $stage);
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
        $path = $this->resolve($relative);
        if (!is_file($path) || !is_readable($path)) {
            throw new FileError('Checksums require a readable regular file.', 403);
        }
        $size = filesize($path);
        if ($size === false || $size > 100 * 1024 * 1024) {
            throw new FileError('Checksums currently support files up to 100 MB.', 413);
        }
        $value = hash_file('sha256', $path);
        if ($value === false) throw new FileError('Could not compute the checksum.', 500);
        clearstatcache(true, $path);
        if (filesize($path) !== $size) throw new FileError('File changed during checksum calculation.', 409);
        return ['path' => $this->relative($relative), 'algorithm' => 'SHA-256', 'checksum' => $value, 'size' => $size];
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

    public function zip(string $relative): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileError('PHP ZIP extension is not installed.', 501);
        }
        [$parent, $parentRel, $name] = $this->parentOf($relative);
        $source = $this->resolve($relative);
        $archiveName = $name . '.zip';
        $relativeTarget = ($parentRel ? $parentRel . '/' : '') . $archiveName;
        $target = $this->resolve($relativeTarget, false);
        if (file_exists($target) || is_link($target) || !is_writable($parent)) {
            throw new FileError('Archive destination already exists or is not writable.', 409);
        }
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new FileError('Could not initialize archive.', 500);
        }
        $total = 0;
        $count = 0;
        try {
            if (is_file($source)) {
                $bytes = filesize($source);
                if ($bytes === false || $bytes > 100 * 1024 * 1024) {
                    throw new FileError('Archive size limit exceeded (100 MB).', 413);
                }
                $zip->addFile($source, $name);
            } else {
                $zip->addEmptyDir($name);
                $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iter as $entry) {
                    if (++$count > $this->maxEntries || $iter->getDepth() > 20 || $entry->isLink()) {
                        throw new FileError('Archive entry, nesting, or symlink limit exceeded.', 413);
                    }
                    $pathname = $entry->getPathname();
                    $this->resolve($this->relative($relative) . substr($pathname, strlen($source)));
                    $localName = $name . substr($pathname, strlen($source));
                    $localName = str_replace(DIRECTORY_SEPARATOR, '/', $localName);
                    if ($entry->isDir()) {
                        $zip->addEmptyDir($localName);
                    } elseif ($entry->isFile()) {
                        $total += $entry->getSize();
                        if ($total > 100 * 1024 * 1024) {
                            throw new FileError('Archive size limit exceeded (100 MB).', 413);
                        }
                        $zip->addFile($pathname, $localName);
                    }
                }
            }
            if (!$zip->close()) {
                throw new FileError('Could not finalize archive.', 500);
            }
            return ['path' => $relativeTarget];
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($target);
            throw $e;
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
