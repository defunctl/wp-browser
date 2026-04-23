<?php

namespace lucatume\WPBrowser\Command\ParallelRun;

final class WorkerResourceEnv
{
    public const ENV_NEEDS_SERVER       = 'WPBROWSER_PARALLEL_WORKER_NEEDS_SERVER';
    public const ENV_NEEDS_CHROMEDRIVER = 'WPBROWSER_PARALLEL_WORKER_NEEDS_CHROMEDRIVER';

    /**
     * Returns true when the producer-set env var exists and equals exactly the string "0".
     * Any other value (unset, "1", "true", etc.) returns false — fail-safe by design.
     */
    public static function isDisabled(string $envVar): bool
    {
        $value = $_SERVER[$envVar] ?? $_ENV[$envVar] ?? getenv($envVar);
        return $value !== false && $value !== null && (string)$value === '0';
    }
}
