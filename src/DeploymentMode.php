<?php
declare(strict_types=1);

namespace ForgeFileManager;

/**
 * No automatic virtual demo and no public default account.
 * Public test credentials are restricted to an explicit, local-only sandbox.
 */
final class DeploymentMode
{
    public static function sandboxEnabled(): bool
    {
        return getenv('FFM_TEST_SANDBOX') === '1';
    }

    public static function sandboxRoot(): string
    {
        if (!self::sandboxEnabled() || getenv('FFM_ALLOW_HTTP_DEV') !== '1') {
            throw new FileError('Test sandbox is disabled outside explicit local development.', 503);
        }
        // Do not trust forwarded IP headers from a proxy claiming loopback.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($ip, ['127.0.0.1', '::1'], true) ||
            isset($_SERVER['HTTP_FORWARDED']) || isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            throw new FileError('Test credentials are restricted to local development.', 403);
        }
        foreach (['FFM_USERNAME', 'FFM_PASSWORD_HASH', 'FFM_STORAGE_ROOT', 'FFM_TRASH_ROOT'] as $key) {
            if ((string) getenv($key) !== '') {
                throw new FileError('Test sandbox must not be mixed with live account or trash settings.', 503);
            }
        }
        $configured = (string) getenv('FFM_TEST_STORAGE_ROOT');
        $root = realpath($configured);
        if (!$root || $root === '/' || !is_dir($root) ||
            (fileperms($root) & 0077) !== 0 || !is_writable($root)) {
            throw new FileError('A private owner-only FFM_TEST_STORAGE_ROOT is required.', 503);
        }
        $source = realpath(dirname(__DIR__));
        $web = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        foreach ([$source, $web] as $base) {
            if ($base && ($root === $base || str_starts_with($root, $base . '/'))) {
                throw new FileError('Test storage root must be outside web and application directories.', 503);
            }
        }
        return $root;
    }
}
