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

    /**
     * Build per-worker env var map from shard file assignments.
     *
     * @param array<int, array{files: string[], weight: float}> $shardAssignments 1-indexed
     * @return array<int, array<string,string>> 1-indexed, each inner array contains ENV_NEEDS_* => "0"|"1"
     */
    public static function build(array $shardAssignments, bool $isShardMode): array
    {
        $planner = new ShardPlanner();
        $out = [];
        foreach ($shardAssignments as $i => $shard) {
            if ($isShardMode) {
                $need = ['server' => true, 'chromedriver' => true, 'mysql' => true];
            } else {
                $files = $shard['files'] ?? [];
                $need  = $planner->needsResources($files);
            }
            $out[$i] = [
                self::ENV_NEEDS_SERVER       => $need['server'] ? '1' : '0',
                self::ENV_NEEDS_CHROMEDRIVER => $need['chromedriver'] ? '1' : '0',
            ];
        }
        return $out;
    }
}
