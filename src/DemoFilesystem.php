<?php
declare(strict_types=1);

namespace ForgeFileManager;

require_once __DIR__ . '/DirectoryPager.php';

/**
 * Deliberately virtual, read-only demo. Never touches the hosting filesystem.
 * Do NOT map this identity to FFM_STORAGE_ROOT or grant it write capabilities.
 */
final class DemoFilesystem
{
    /** @var array<string, array{type: string, content?: string, mode: string}> */
    private const ENTRIES = [
        'public_html' => ['type' => 'directory', 'mode' => '755'],
        'public_html/index.php' => ['type' => 'file', 'mode' => '644', 'content' => "<?php\n// Forge File Manager — demonstration only\necho 'Hello from Forge File Manager!';\n"],
        'public_html/assets' => ['type' => 'directory', 'mode' => '755'],
        'public_html/assets/style.css' => ['type' => 'file', 'mode' => '644', 'content' => "/* Demo stylesheet */\nbody { font-family: system-ui, sans-serif; }\n"],
        'logs' => ['type' => 'directory', 'mode' => '750'],
        'logs/example.log' => ['type' => 'file', 'mode' => '640', 'content' => "[DEMO] No real server logs are displayed here.\n"],
        'README.txt' => ['type' => 'file', 'mode' => '644', 'content' => "Forge File Manager demo\n\nUsername: demo\nPassword: demo123\n\nThis is a virtual read-only sandbox.\nNo files or settings on the server can be changed in demo mode.\n"],
    ];

    private static function entry(string $path): ?array
    {
        if ($path === '' || strlen($path) > 512 || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        return self::ENTRIES[$path] ?? null;
    }

    public static function listing(string $path, int $page = 1, string $search = '', string $sort = 'name', bool $showHidden = false): array
    {
        if ($path !== '') {
            $entry = self::entry($path);
            if ($entry === null || $entry['type'] !== 'directory') {
                throw new FileError('Demo folder not found.', 404);
            }
        }
        $prefix = $path === '' ? '' : $path . '/';
        $items = [];
        foreach (self::ENTRIES as $name => $entry) {
            if (!str_starts_with($name, $prefix)) continue;
            $relativeName = substr($name, strlen($prefix));
            if ($relativeName === '' || str_contains($relativeName, '/')) continue;
            $directory = $entry['type'] === 'directory';
            $content = $entry['content'] ?? '';
            $items[] = [
                'name' => $relativeName, 'path' => $name, 'type' => $entry['type'],
                'size' => $directory ? null : strlen($content), 'modified' => 1763856000,
                'mode' => $entry['mode'], 'readable' => true, 'writable' => false,
                'extension' => $directory ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            ];
        }
        $pagination = DirectoryPager::paginate($items, $page, $search, $sort, $showHidden);
        return ['path' => $path, ...$pagination, 'truncated' => false,
            'disk' => ['free' => null, 'total' => null, 'isAccountQuota' => false]];
    }

    public static function readText(string $path): array
    {
        $entry = self::entry($path);
        if (!$entry || $entry['type'] !== 'file') throw new FileError('Demo file not found.', 404);
        $content = $entry['content'] ?? '';
        return ['path' => $path, 'content' => $content, 'hash' => hash('sha256', $content), 'maxBytes' => 1048576];
    }

    public static function checksum(string $path): array
    {
        $data = self::readText($path);
        return ['path' => $path, 'algorithm' => 'SHA-256', 'checksum' => $data['hash'], 'size' => strlen($data['content'])];
    }
}
