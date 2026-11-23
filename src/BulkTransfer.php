<?php
declare(strict_types=1);

namespace ForgeFileManager;

/**
 * Synchronous bounded multi-selection copy/move. Each item has a separate
 * outcome; a partial result is never presented as a transaction or rollback.
 */
final class BulkTransfer
{
    private const MAX_SELECTED = 25;

    /**
     * @param mixed $paths
     * @return array{mode:string,success:int,failed:int,results:list<array<string,mixed>>}
     */
    public static function execute(Filesystem $fs, mixed $paths, string $directory, string $mode): array
    {
        if (!in_array($mode, ['copy', 'move'], true)) {
            throw new FileError('Bulk transfer must be copy or move.', 400);
        }
        if (!is_array($paths) || !array_is_list($paths) || count($paths) < 1 || count($paths) > self::MAX_SELECTED) {
            throw new FileError('Select between 1 and 25 files or folders.', 400);
        }
        $seen = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || isset($seen[$path])) {
                throw new FileError('Bulk selection contains invalid or duplicate paths.', 400);
            }
            $fs->resolve($path);
            $seen[$path] = true;
        }
        foreach (array_keys($seen) as $path) {
            $parts = explode('/', $path);
            array_pop($parts);
            while ($parts !== []) {
                if (isset($seen[implode('/', $parts)])) {
                    throw new FileError('Cannot transfer a selected folder together with its descendant.', 400);
                }
                array_pop($parts);
            }
        }
        $destination = $fs->resolve($directory);
        if (!is_dir($destination) || !is_writable($destination)) {
            throw new FileError('Bulk destination must be a writable directory.', 403);
        }
        $results = [];
        $success = 0;
        foreach ($paths as $path) {
            try {
                $result = $fs->transfer($path, $directory, $mode);
                $results[] = ['source' => $path, 'ok' => true, 'path' => $result['path']];
                ++$success;
            } catch (FileError $e) {
                $results[] = ['source' => $path, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        return ['mode' => $mode, 'success' => $success, 'failed' => count($results) - $success, 'results' => $results];
    }
}
