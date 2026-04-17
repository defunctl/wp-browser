<?php

namespace Unit\lucatume\WPBrowser\Command\ParallelRun;

use Codeception\Test\Unit;
use lucatume\WPBrowser\Command\ParallelRun\WorkerEnv;
use lucatume\WPBrowser\Utils\Filesystem as FS;

/**
 * @group fast
 */
class WorkerEnvTest extends Unit
{
    public function test_worker_0_keeps_base_ports_and_applies_suffix(): void
    {
        $env = WorkerEnv::build(0, []);

        $this->assertSame('2389', $env['WORDPRESS_LOCALHOST_PORT']);
        $this->assertSame('2391', $env['WORDPRESS_DB_LOCALHOST_PORT']);
        $this->assertSame('2390', $env['CHROMEDRIVER_PORT']);
        $this->assertSame('var/tmp/w0', $env['TEST_TMP_ROOT_DIR']);
        $this->assertSame('var/tmp/_cache/w0', $env['TEST_CACHE_DIR']);
        $this->assertSame('var/w0/wordpress', $env['WORDPRESS_ROOT_DIR']);
        $this->assertSame('0', $env['WPBROWSER_WORKER_ID']);
    }

    public function test_worker_3_shifts_ports_by_stride_times_three(): void
    {
        $env = WorkerEnv::build(3, []);

        $this->assertSame('2419', $env['WORDPRESS_LOCALHOST_PORT']);
        $this->assertSame('2421', $env['WORDPRESS_DB_LOCALHOST_PORT']);
        $this->assertSame('2420', $env['CHROMEDRIVER_PORT']);
        $this->assertSame('var/tmp/w3', $env['TEST_TMP_ROOT_DIR']);
        $this->assertSame('3', $env['WPBROWSER_WORKER_ID']);
    }

    public function test_port_bearing_urls_and_dsns_are_rewritten_from_dotenv(): void
    {
        $tmp = FS::tmpDir('worker-env-', ['.env' => implode("\n", [
            'WORDPRESS_URL=http://localhost:2389',
            'WORDPRESS_DOMAIN=localhost:2389',
            'WORDPRESS_SUBDIR_URL=http://localhost:2389/subdir',
            'WORDPRESS_SUBDOMAIN_URL=http://test1.localhost:2389',
            'WORDPRESS_DB_DSN=mysql:host=127.0.0.1;port=2391;dbname=wordpress',
            'WORDPRESS_DB_HOST=127.0.0.1:2391',
            'WORDPRESS_DB_URL=mysql://root:password@127.0.0.1:2391/wordpress',
            'WORDPRESS_SUBDIR_DB_DSN=mysql:host=127.0.0.1;port=2391;dbname=test_subdir',
            'WORDPRESS_SUBDOMAIN_DB_DSN=mysql:host=127.0.0.1;port=2391;dbname=test_subdomain',
            'WORDPRESS_EMPTY_DB_DSN=mysql:host=127.0.0.1;port=2391;dbname=empty',
            '',
        ])]);

        $env = WorkerEnv::build(2, [], $tmp . '/.env');

        $this->assertSame('http://localhost:2409', $env['WORDPRESS_URL']);
        $this->assertSame('localhost:2409', $env['WORDPRESS_DOMAIN']);
        $this->assertSame('http://localhost:2409/subdir', $env['WORDPRESS_SUBDIR_URL']);
        $this->assertSame('http://test1.localhost:2409', $env['WORDPRESS_SUBDOMAIN_URL']);
        $this->assertSame('mysql:host=127.0.0.1;port=2411;dbname=wordpress', $env['WORDPRESS_DB_DSN']);
        $this->assertSame('127.0.0.1:2411', $env['WORDPRESS_DB_HOST']);
        $this->assertSame('mysql://root:password@127.0.0.1:2411/wordpress', $env['WORDPRESS_DB_URL']);
        $this->assertSame('mysql:host=127.0.0.1;port=2411;dbname=test_subdir', $env['WORDPRESS_SUBDIR_DB_DSN']);
        $this->assertSame('mysql:host=127.0.0.1;port=2411;dbname=test_subdomain', $env['WORDPRESS_SUBDOMAIN_DB_DSN']);
        $this->assertSame('mysql:host=127.0.0.1;port=2411;dbname=empty', $env['WORDPRESS_EMPTY_DB_DSN']);
    }

    public function test_dotenv_values_override_base_env(): void
    {
        $tmp = FS::tmpDir('worker-env-override-', ['.env' => "CUSTOM_KEY=from-dotenv\nWPBROWSER_VERSION=9.9.9\n"]);

        $env = WorkerEnv::build(0, ['CUSTOM_KEY' => 'from-base'], $tmp . '/.env');

        $this->assertSame('from-dotenv', $env['CUSTOM_KEY']);
        $this->assertSame('9.9.9', $env['WPBROWSER_VERSION']);
    }

    public function test_base_env_values_are_preserved_when_not_in_dotenv(): void
    {
        $env = WorkerEnv::build(0, ['PATH' => '/usr/bin', 'HOME' => '/home/x']);

        $this->assertSame('/usr/bin', $env['PATH']);
        $this->assertSame('/home/x', $env['HOME']);
    }

    public function test_missing_dotenv_file_is_tolerated(): void
    {
        $env = WorkerEnv::build(1, ['FOO' => 'bar'], '/does/not/exist/.env');

        $this->assertSame('bar', $env['FOO']);
        $this->assertSame('2399', $env['WORDPRESS_LOCALHOST_PORT']);
    }

    public function test_non_scalar_base_env_values_are_dropped(): void
    {
        $env = WorkerEnv::build(0, [
            'OK'     => 'value',
            'ARRAY'  => ['a', 'b'],
            'OBJECT' => new \stdClass(),
            'NULLY'  => null,
        ]);

        $this->assertSame('value', $env['OK']);
        $this->assertSame('', $env['NULLY']);
        $this->assertArrayNotHasKey('ARRAY', $env);
        $this->assertArrayNotHasKey('OBJECT', $env);
    }

    public function test_tmp_and_cache_dirs_suffix_even_if_base_env_provides_them(): void
    {
        $env = WorkerEnv::build(2, [
            'TEST_TMP_ROOT_DIR' => '/custom/tmp',
            'TEST_CACHE_DIR'    => '/custom/cache',
        ]);

        $this->assertSame('/custom/tmp/w2', $env['TEST_TMP_ROOT_DIR']);
        $this->assertSame('/custom/cache/w2', $env['TEST_CACHE_DIR']);
    }
}
