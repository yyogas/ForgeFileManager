<?php
declare(strict_types=1);

namespace ForgeFileManager;

/**
 * Determines the deployment profile without ever exposing actual credentials.
 * Empty configuration provides a safe virtual read-only demonstration.
 */
final class DeploymentMode
{
    public static function demoEnabled(): bool
    {
        $flag = getenv('FFM_DEMO_MODE');
        if ($flag === '1') return true;
        if ($flag === '0') return false;
        // Automatic demo is allowed only when *none* of the live credentials are present.
        // Partially configured live deployments must fail closed (503) rather than falling back.
        return ((string) getenv('FFM_USERNAME') === '') &&
            ((string) getenv('FFM_PASSWORD_HASH') === '') &&
            ((string) getenv('FFM_STORAGE_ROOT') === '');
    }
}
