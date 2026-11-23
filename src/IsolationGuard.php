<?php
declare(strict_types=1);

namespace ForgeFileManager;

/**
 * Enforce the deployment contract before constructing a filesystem manager.
 * One OS identity/PHP-FPM pool per hosting account remains mandatory: PHP
 * path checks alone cannot isolate two customers sharing the same Unix UID.
 */
final class IsolationGuard
{
    public static function localDevelopment(): bool
    {
        return PHP_SAPI === 'cli-server'
            && getenv('FFM_ALLOW_HTTP_DEV') === '1'
            && getenv('FFM_ALLOW_ROOT_DEV') === '1'
            && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
            && !isset($_SERVER['HTTP_FORWARDED'])
            && !isset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public static function assertAccount(string $storageRoot, bool $sandbox): void
    {
        $canonical = realpath($storageRoot);
        if ($canonical === false || !is_dir($canonical) || is_link($storageRoot)) {
            throw new FileError('Storage root must be an existing, non-symlink directory.', 503);
        }
        // Reject common system, shared and multi-tenant parent directories.
        if (in_array($canonical, ['/', '/home', '/srv', '/var', '/var/www', '/www', '/tmp', '/etc', '/opt', '/usr', '/root'], true)) {
            throw new FileError('Refusing a system-wide or multi-account storage root.', 503);
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!$sandbox && $uid === null) {
            throw new FileError('POSIX worker UID checks are required for live hosting.', 503);
        }
        $owner = @fileowner($canonical);
        $expectedUid = (string) getenv('FFM_EXPECTED_UID');
        if ($expectedUid !== '') {
            if (!ctype_digit($expectedUid) || $uid === null || (int) $expectedUid !== $uid) {
                throw new FileError('The PHP worker UID does not match FFM_EXPECTED_UID.', 503);
            }
        }
        if (!$sandbox && $uid !== null) {
            if ($uid === 0 && !self::localDevelopment()) {
                throw new FileError('Refusing to manage hosting files as root.', 403);
            }
            if ($uid !== 0 && ($owner === false || $owner !== $uid)) {
                throw new FileError('Storage root ownership does not match the PHP worker UID.', 503);
            }
        }
    }

    /** Changes to any account-scoping or credential setting invalidate old sessions. */
    public static function binding(string $username, string $storageRoot, string $hash, bool $sandbox, bool $showcase = false, bool $playground = false): string
    {
        return hash('sha256', implode("\0", [
            $username, (string) realpath($storageRoot), $hash,
            $playground ? 'public-playground' : ($showcase ? 'public-showcase' : ($sandbox ? 'sandbox' : 'live')),
            function_exists('posix_geteuid') ? (string) posix_geteuid() : 'unknown',
        ]));
    }
}
