<?php
declare(strict_types=1);

namespace ForgeFileManager;

use DirectoryIterator;
use ZipArchive;

/**
 * ZIP extraction within a dedicated account-scoped directory. Never merge into
 * an existing tree. Requires the worker to run as the hosting account, not root.
 * This cannot defend against a hostile process with the SAME OS identity.
 */
final class ArchiveExtractor
{
    private const MAX_ENTRIES = 2000;
    private const MAX_DEPTH = 20;
    private const MAX_BYTES = 104857600; // 100 MiB total uncompressed
    private const MAX_COMPRESSED_BYTES = 104857600;
    private const MAX_RATIO = 250;

    /** @return array{path:string,entries:int,bytes:int} */
    public static function extract(Filesystem $fs, string $archiveRelative, string $destinationDirectory): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new FileError('ZIP extraction requires the PHP zip extension.', 501);
        }
        $archive = $fs->resolve($archiveRelative);
        if (!is_file($archive) || !is_readable($archive) || !str_ends_with(strtolower($archive), '.zip')) {
            throw new FileError('Select a readable .zip file.', 415);
        }
        $compressed = @filesize($archive);
        if ($compressed === false || $compressed > self::MAX_COMPRESSED_BYTES) {
            throw new FileError('ZIP file exceeds the 100 MiB size limit.', 413);
        }
        $targetDir = $fs->resolve($destinationDirectory);
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            throw new FileError('ZIP extraction directory is not writable.', 403);
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) {
            throw new FileError('ZIP archive is corrupt or cannot be opened.', 415);
        }
        $stage = null;
        try {
            $entries = self::inspect($zip);
            $destinationRel = self::outputName($fs, $archive, $destinationDirectory);
            // The stage must remain on the same filesystem as the destination.
            $stage = $targetDir . '/.ffm-extract-' . bin2hex(random_bytes(16));
            if (!@mkdir($stage, 0700)) {
                throw new FileError('Unable to create a private extraction staging directory.', 500);
            }
            $total = 0;
            foreach ($entries as $entry) {
                $absolute = $stage . '/' . $entry['name'];
                if ($entry['directory']) {
                    self::mkdirs($stage, $entry['name']);
                    continue;
                }
                self::mkdirs($stage, dirname($entry['name']));
                $from = $zip->getStreamIndex($entry['index']);
                if (!is_resource($from)) throw new FileError('ZIP entry cannot be decompressed.', 415);
                $to = @fopen($absolute, 'xb');
                if (!is_resource($to)) {
                    fclose($from);
                    throw new FileError('ZIP entry cannot be written safely.', 409);
                }
                $bytes = 0;
                $crc = hash_init('crc32b');
                try {
                    while (!feof($from)) {
                        $chunk = fread($from, 65536);
                        if ($chunk === false) throw new FileError('ZIP decompression failed.', 415);
                        if ($chunk === '' && !feof($from)) throw new FileError('ZIP decompression made no progress.', 415);
                        $bytes += strlen($chunk);
                        $total += strlen($chunk);
                        if ($bytes > $entry['size'] || $total > self::MAX_BYTES) {
                            throw new FileError('ZIP decompression exceeded its declared size or budget.', 413);
                        }
                        hash_update($crc, $chunk);
                        if ($chunk !== '' && fwrite($to, $chunk) !== strlen($chunk)) {
                            throw new FileError('ZIP entry could not be fully written.', 500);
                        }
                    }
                    if ($bytes !== $entry['size'] || !hash_equals($entry['crc'], hash_final($crc))) {
                        throw new FileError('ZIP entry size or CRC does not match the archive.', 415);
                    }
                    if (!fflush($to)) throw new FileError('ZIP entry could not be flushed.', 500);
                } finally {
                    fclose($from);
                    fclose($to);
                }
            }
            $destination = $fs->resolve($destinationRel, false);
            // Recheck immediately before final publication. As with other PHP
            // rename calls, this is not a replacement for OS-level isolation.
            if (file_exists($destination) || is_link($destination) || !@rename($stage, $destination)) {
                throw new FileError('ZIP extraction destination is no longer available.', 409);
            }
            $stage = null;
            return ['path' => $destinationRel, 'entries' => count($entries), 'bytes' => $total];
        } finally {
            $zip->close();
            if ($stage !== null) self::removeStage($stage);
        }
    }

    /** @return list<array{index:int,name:string,directory:bool,size:int,crc:string}> */
    private static function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new FileError('ZIP exceeds the 2000 entry limit.', 413);
        }
        $files = [];
        $paths = [];
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);
            if (!is_string($name) || !is_array($stat)) throw new FileError('ZIP entry metadata is invalid.', 415);
            $directory = str_ends_with($name, '/');
            $clean = $directory ? substr($name, 0, -1) : $name;
            self::validateName($clean);
            $size = $stat['size'] ?? null;
            $compressed = $stat['comp_size'] ?? null;
            if (!is_int($size) || $size < 0 || !is_int($compressed) || $compressed < 0) {
                throw new FileError('Invalid ZIP entry sizes.', 415);
            }
            $method = $stat['comp_method'] ?? -1;
            if (!in_array($method, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new FileError('ZIP compression method is not allowed.', 415);
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw new FileError('Encrypted ZIP archives are not supported.', 415);
            }
            $opsys = 0; $attributes = 0;
            if (!$zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                throw new FileError('ZIP entry attributes are unavailable.', 415);
            }
            if ($opsys === ZipArchive::OPSYS_UNIX) {
                $type = ($attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== ($directory ? 0040000 : 0100000)) {
                    throw new FileError('ZIP contains a symbolic link or special file.', 403);
                }
            }
            if (isset($paths[$clean])) throw new FileError('ZIP contains duplicate entry names.', 415);
            $paths[$clean] = $directory ? 'directory' : 'file';
            if ($directory && $size !== 0) throw new FileError('ZIP directory has unexpected content.', 415);
            if (!$directory) {
                if (($compressed === 0 && $size > 0) || ($compressed > 0 && $size > $compressed * self::MAX_RATIO)) {
                    throw new FileError('ZIP decompression ratio is unsafe.', 413);
                }
                $bytes += $size;
                if ($bytes > self::MAX_BYTES) throw new FileError('ZIP extraction exceeds the 100 MiB limit.', 413);
            }
            $files[] = [
                'index' => $i, 'name' => $clean, 'directory' => $directory,
                'size' => $size, 'crc' => sprintf('%08x', (int) ($stat['crc'] ?? 0)),
            ];
        }
        foreach ($paths as $name => $type) {
            $parent = dirname($name);
            while ($parent !== '.') {
                if (($paths[$parent] ?? null) === 'file') {
                    throw new FileError('ZIP directory structure conflicts with a file.', 415);
                }
                $parent = dirname($parent);
            }
        }
        return $files;
    }

    private static function validateName(string $path): void
    {
        if ($path === '' || strlen($path) > 3000 || str_starts_with($path, '/') || str_contains($path, '\\') ||
            str_contains($path, "\0") || preg_match('/[\x00-\x1f\x7f]/', $path) || !preg_match('//u', $path) ||
            preg_match('/^[a-zA-Z]:/', $path)) {
            throw new FileError('ZIP contains an unsafe path.', 403);
        }
        $parts = explode('/', $path);
        if (count($parts) > self::MAX_DEPTH) throw new FileError('ZIP nesting exceeds 20 levels.', 413);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) {
                throw new FileError('ZIP contains an invalid path segment.', 403);
            }
        }
    }

    private static function mkdirs(string $stage, string $relative): void
    {
        if ($relative === '.' || $relative === '') return;
        $next = $stage;
        foreach (explode('/', $relative) as $component) {
            $next .= '/' . $component;
            if (is_link($next)) throw new FileError('Unsafe ZIP staging link.', 403);
            if (is_dir($next)) continue;
            if (file_exists($next) || !@mkdir($next, 0700)) {
                throw new FileError('ZIP contains conflicting file and directory entries.', 409);
            }
        }
    }

    private static function outputName(Filesystem $fs, string $archive, string $dir): string
    {
        $folder = $fs->resolve($dir);
        $base = substr(basename($archive), 0, -4);
        $base = substr($base === '' || $base === '.' ? 'archive' : $base, 0, 180);
        for ($i = 0; $i < 100; ++$i) {
            $suffix = $i === 0 ? ' (extracted)' : ' (extracted ' . ($i + 1) . ')';
            $name = $base . $suffix;
            if (!file_exists($folder . '/' . $name) && !is_link($folder . '/' . $name)) {
                $relative = $dir === '' ? $name : trim($dir, '/') . '/' . $name;
                $fs->resolve($relative, false);
                return $relative;
            }
        }
        throw new FileError('No free extraction destination name.', 409);
    }

    private static function removeStage(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) self::removeStage($entry->getPathname());
        }
        @rmdir($path);
    }
}
