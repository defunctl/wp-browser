<?php

namespace lucatume\WPBrowser\Command\ParallelRun;

use lucatume\WPBrowser\Utils\Env;

final class WorkerEnv
{
    public const PORT_STRIDE = 10;

    private const BASE_PORTS = [
        'WORDPRESS_LOCALHOST_PORT'    => 2389,
        'WORDPRESS_DB_LOCALHOST_PORT' => 2391,
        'CHROMEDRIVER_PORT'           => 2390,
    ];

    private const EXTENSION_PORTS = [
        'lucatume\\WPBrowser\\Extension\\ChromeDriverController'  => 'CHROMEDRIVER_PORT',
        'lucatume\\WPBrowser\\Extension\\BuiltInServerController' => 'WORDPRESS_LOCALHOST_PORT',
        'lucatume\\WPBrowser\\Extension\\MysqlServerController'   => 'WORDPRESS_DB_LOCALHOST_PORT',
    ];

    private const REWRITE_NEEDLES = [
        'WORDPRESS_URL'              => ':2389',
        'WORDPRESS_DOMAIN'           => ':2389',
        'WORDPRESS_SUBDIR_URL'       => ':2389',
        'WORDPRESS_SUBDOMAIN_URL'    => ':2389',
        'WORDPRESS_DB_DSN'           => 'port=2391',
        'WORDPRESS_DB_HOST'          => ':2391',
        'WORDPRESS_DB_URL'           => ':2391',
        'WORDPRESS_SUBDIR_DB_DSN'    => 'port=2391',
        'WORDPRESS_SUBDOMAIN_DB_DSN' => 'port=2391',
        'WORDPRESS_EMPTY_DB_DSN'     => 'port=2391',
    ];

    /**
     * @param array<string,mixed>   $baseEnv
     * @param array<string,int>|null $portOverrides Map of BASE_PORTS keys to chosen ports; falls back to stride math.
     * @return array<string,string>
     */
    public static function build(
        int $workerIndex,
        array $baseEnv,
        ?string $envFilePath = null,
        ?array $portOverrides = null
    ): array {
        $env = self::stringifyEnv($baseEnv);

        if ($envFilePath !== null && is_file($envFilePath)) {
            foreach (Env::envFile($envFilePath) as $k => $v) {
                $env[(string)$k] = (string)$v;
            }
        }

        $ports = self::resolvePorts($workerIndex, $portOverrides);
        foreach ($ports as $name => $port) {
            $env[$name] = (string)$port;
        }

        foreach (self::REWRITE_NEEDLES as $key => $needle) {
            if (!isset($env[$key])) {
                continue;
            }
            $isDb    = str_contains($key, 'DB');
            $newPort = $isDb ? $ports['WORDPRESS_DB_LOCALHOST_PORT'] : $ports['WORDPRESS_LOCALHOST_PORT'];
            $replace = str_replace('2391', (string)$newPort, str_replace('2389', (string)$newPort, $needle));
            $env[$key] = str_replace($needle, $replace, $env[$key]);
        }

        $suffix = "w{$workerIndex}";
        $env['TEST_TMP_ROOT_DIR']   = ($env['TEST_TMP_ROOT_DIR']  ?? 'var/tmp')        . "/{$suffix}";
        $env['TEST_CACHE_DIR']      = ($env['TEST_CACHE_DIR']     ?? 'var/tmp/_cache') . "/{$suffix}";
        $env['WORDPRESS_ROOT_DIR']  = "var/{$suffix}/wordpress";
        $env['WPBROWSER_WORKER_ID'] = (string)$workerIndex;

        return $env;
    }

    /**
     * @param array<string,int>|null $portOverrides
     * @return array<int,string>
     */
    public static function overridesForWorker(int $workerIndex, ?array $portOverrides = null): array
    {
        $ports = self::resolvePorts($workerIndex, $portOverrides);
        $tokens = [];
        foreach (self::EXTENSION_PORTS as $fqcn => $portVar) {
            $tokens[] = '--override';
            $tokens[] = sprintf("extensions: config: '%s': port: %d", $fqcn, $ports[$portVar]);
        }
        return $tokens;
    }

    /**
     * @param array<string,int>|null $portOverrides
     * @return array<string,int>
     */
    private static function resolvePorts(int $workerIndex, ?array $portOverrides): array
    {
        $ports = [];
        foreach (self::BASE_PORTS as $name => $base) {
            $ports[$name] = $portOverrides[$name] ?? ($base + ($workerIndex * self::PORT_STRIDE));
        }
        return $ports;
    }

    /**
     * @param array<string,mixed> $baseEnv
     * @return array<string,string>
     */
    private static function stringifyEnv(array $baseEnv): array
    {
        $out = [];
        foreach ($baseEnv as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $out[(string)$k] = (string)($v ?? '');
            }
        }
        return $out;
    }
}
