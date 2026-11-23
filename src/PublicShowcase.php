<?php
declare(strict_types=1);

namespace ForgeFileManager;

/**
 * Explicitly configured PUBLIC PREVIEW. Real, fixed sample files only.
 * Access through HTTP is always read-only. This cannot manage hosting data.
 */
final class PublicShowcase
{
    private const MARKER = '.forge-public-showcase.json';
    private const MARKER_DATA = "{\"product\":\"Forge File Manager\",\"profile\":\"public-showcase\",\"version\":1}\n";

    public static function enabled(): bool
    {
        return getenv('FFM_PUBLIC_SHOWCASE') === '1';
    }

    /** @return array<string,string> Fixed, intentionally public sample content. */
    public static function sampleFiles(): array
    {
        return [
            'README.txt' => "FORGE FILE MANAGER — PUBLIC PREVIEW\n\nThese are deliberately synthetic, read-only files.\nNo actual hosting account data or secrets are available here.\n\nLogin: demo / demo123\n",
            'public_html/index.html' => "<!doctype html>\n<html lang=\"en\"><meta charset=\"utf-8\"><title>Example website</title><h1>Example website</h1><p>Static sample content for Forge File Manager.</p></html>\n",
            'public_html/assets/main.css' => "/* Public preview sample — not a real customer website */\nbody { font-family: sans-serif; margin: 2rem; }\n",
            'public_html/robots.txt' => "User-agent: *\nDisallow: /\n",
            'logs/README.txt' => "Example logs directory; no real server logs are provided.\n",
            'backups/README.txt' => "Example backup directory; no production backups are provided.\n",
            'private/NOTICE.txt' => "Example directory only; no credentials or personal information.\n",
        ];
    }

    /** @return array<string,true> */
    private static function allowedEntries(): array
    {
        $allowed = [self::MARKER => true];
        foreach (self::sampleFiles() as $path => $_) {
            $allowed[$path] = true;
            $parts = explode('/', $path);
            array_pop($parts);
            while ($parts !== []) {
                $allowed[implode('/', $parts)] = true;
                array_pop($parts);
            }
        }
        return $allowed;
    }

    private static function checkPathOutside(string $root): void
    {
        $source = realpath(dirname(__DIR__));
        $web = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        foreach ([$source, $web] as $base) {
            if ($base && ($root === $base || str_starts_with($root, $base . '/'))) {
                throw new FileError('The public showcase must be outside application and web directories.', 503);
            }
        }
    }

    /** Install synthetic files in an existing, empty, private owner-controlled directory. CLI only. */
    public static function initialize(string $provided): void
    {
        if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            throw new FileError('Initialize the showcase from CLI as a non-root account user.', 403);
        }
        $root = realpath($provided);
        if (!$root || !is_dir($root) || is_link($provided) || $root === '/') {
            throw new FileError('Provide an existing, non-symlink directory outside the webroot.', 400);
        }
        if (in_array($root, ['/home', '/srv', '/var', '/var/www', '/tmp', '/etc', '/opt', '/root'], true)) {
            throw new FileError('Refusing a system or shared root.', 403);
        }
        self::checkPathOutside($root);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($uid === null || fileowner($root) !== $uid || (fileperms($root) & 0077) !== 0) {
            throw new FileError('Showcase directory must belong to the current Unix account and have mode 0700.', 403);
        }
        if (count(scandir($root) ?: []) !== 2) {
            throw new FileError('Showcase destination must be empty to prevent overwriting data.', 409);
        }
        umask(0077);
        foreach (self::sampleFiles() as $path => $content) {
            $target = $root . '/' . $path;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
                throw new FileError('Cannot create private showcase directories.', 500);
            }
            if (file_put_contents($target, $content, LOCK_EX) !== strlen($content)) {
                throw new FileError('Cannot create sample file.', 500);
            }
            chmod($target, 0600);
        }
        file_put_contents($root . '/' . self::MARKER, self::MARKER_DATA, LOCK_EX);
        chmod($root . '/' . self::MARKER, 0600);
        self::validateContents($root);
    }

    public static function root(): string
    {
        if (!self::enabled() || getenv('FFM_TEST_SANDBOX') === '1' || getenv('FFM_DEMO_MODE') === '1') {
            throw new FileError('Public showcase must be explicitly enabled and cannot be combined with other demonstration modes.', 503);
        }
        foreach (['FFM_USERNAME', 'FFM_PASSWORD_HASH', 'FFM_STORAGE_ROOT', 'FFM_TRASH_ROOT', 'FFM_TEST_STORAGE_ROOT', 'FFM_TEST_TRASH_ROOT'] as $key) {
            if ((string) getenv($key) !== '') {
                throw new FileError('Public showcase cannot be combined with real hosting credentials or storage.', 503);
            }
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (!$https) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!(PHP_SAPI === 'cli-server' && getenv('FFM_ALLOW_HTTP_DEV') === '1' &&
                in_array($ip, ['127.0.0.1', '::1'], true) &&
                !isset($_SERVER['HTTP_FORWARDED']) && !isset($_SERVER['HTTP_X_FORWARDED_FOR']))) {
                throw new FileError('Public showcase requires HTTPS.', 403);
            }
        }
        $configured = (string) getenv('FFM_PUBLIC_SHOWCASE_ROOT');
        $root = realpath($configured);
        if (!$root || $root === '/' || is_link($configured) || !is_dir($root) ||
            in_array($root, ['/home', '/srv', '/var', '/var/www', '/tmp', '/etc', '/opt', '/root'], true)) {
            throw new FileError('Public showcase requires an initialized private sample directory.', 503);
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($uid === null || $uid === 0 || fileowner($root) !== $uid ||
            (fileperms($root) & 0077) !== 0) {
            throw new FileError('Public showcase root must be private and owned by a dedicated non-root PHP-FPM account.', 503);
        }
        self::checkPathOutside($root);
        self::validateContents($root);
        return $root;
    }

    private static function validateContents(string $root): void
    {
        $marker = $root . '/' . self::MARKER;
        if (is_link($marker) || !is_file($marker) || file_get_contents($marker) !== self::MARKER_DATA) {
            throw new FileError('Showcase marker is missing or invalid.', 503);
        }
        $allowed = self::allowedEntries();
        $seen = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            $rel = substr($entry->getPathname(), strlen($root) + 1);
            if (!isset($allowed[$rel]) || $entry->isLink()) {
                throw new FileError('Public showcase contains an unexpected file or link and is disabled.', 503);
            }
            $stat = @lstat($entry->getPathname());
            if (!is_array($stat) || ($stat['nlink'] ?? 1) > ($entry->isDir() ? 100 : 1) ||
                (fileperms($entry->getPathname()) & 0077) !== 0) {
                throw new FileError('Public showcase sample permissions or link count are invalid.', 503);
            }
            if (fileowner($entry->getPathname()) !== fileowner($root)) {
                throw new FileError('Public showcase sample ownership has changed.', 503);
            }
            if ($entry->isFile()) {
                $expected = $rel === self::MARKER ? self::MARKER_DATA : (self::sampleFiles()[$rel] ?? null);
                if ($expected === null || $entry->getSize() !== strlen($expected) ||
                    hash_file('sha256', $entry->getPathname()) !== hash('sha256', $expected)) {
                    throw new FileError('Showcase sample contents were altered; publication disabled.', 503);
                }
            } elseif (!$entry->isDir()) {
                throw new FileError('Unsupported showcase entry type.', 503);
            }
            $seen[$rel] = true;
        }
        if (count($seen) !== count($allowed)) {
            throw new FileError('Showcase sample files are incomplete.', 503);
        }
    }

    /** Refuse access to the marker and any path not in the fixed sample allowlist. */
    public static function assertAllowedPath(string $relative): void
    {
        if ($relative === '' || $relative === '.') return;
        $allowed = self::allowedEntries();
        if ($relative === self::MARKER || !isset($allowed[$relative])) {
            throw new FileError('Only public sample files may be accessed.', 403);
        }
    }
}
