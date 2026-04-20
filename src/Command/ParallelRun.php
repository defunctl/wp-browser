<?php

namespace lucatume\WPBrowser\Command;

use Codeception\Command\Run;
use Codeception\Configuration;
use Codeception\CustomCommandInterface;
use lucatume\WPBrowser\Adapters\Symfony\Component\Process\Process;
use lucatume\WPBrowser\Command\ParallelRun\DotAggregator;
use lucatume\WPBrowser\Command\ParallelRun\PortAllocator;
use lucatume\WPBrowser\Command\ParallelRun\ShardPlanner;
use lucatume\WPBrowser\Command\ParallelRun\WorkerEnv;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ParallelRun extends Run implements CustomCommandInterface
{
    public static function getCommandName(): string
    {
        return 'parallel-run';
    }

    public function getDescription(): string
    {
        return 'Runs a single suite split across N worker subprocesses with aggregated dot output.';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'workers',
            null,
            InputOption::VALUE_REQUIRED,
            'Number of parallel worker processes (integer, or "auto").',
            '1'
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $workersOpt = $input->getOption('workers');
        $workers    = $this->resolveWorkers(is_scalar($workersOpt) ? (string)$workersOpt : '1');
        $suiteArg   = $input->getArgument('suite');
        $suite      = is_string($suiteArg) ? $suiteArg : '';

        if ($suite === '') {
            $output->writeln('<error>parallel-run requires a suite argument.</error>');
            return 1;
        }
        if ($workers <= 1) {
            return parent::execute($input, $output);
        }
        $testArg = $input->getArgument('test');
        $testPath = is_string($testArg) ? $testArg : '';

        global $argv;
        $codeceptBin = $argv[0] ?? 'vendor/bin/codecept';
        $cwd         = getcwd() ?: null;
        $passThrough = $this->buildPassThroughOptions($input);
        $reportDir   = sys_get_temp_dir() . '/wpbrowser-parallel-' . getmypid();
        if (!is_dir($reportDir) && !mkdir($reportDir, 0777, true) && !is_dir($reportDir)) {
            $output->writeln("<error>Could not create {$reportDir}</error>");
            return 1;
        }

        $aggregator = new DotAggregator($output);
        $output->writeln(sprintf('<info>Running suite "%s" across %d workers</info>', $suite, $workers));

        [$mode, $shardAssignments] = $this->resolveShardPlan($suite, $testPath, $workers, $cwd ?? '.');
        $output->writeln(sprintf('<info>Sharding mode: %s</info>', $mode));
        if ($mode !== ShardPlanner::MODE_SHARD && $shardAssignments !== []) {
            foreach ($shardAssignments as $i => $shard) {
                $output->writeln(sprintf(
                    '  shard %d: %d files, weight %.2f',
                    $i,
                    count($shard['files']),
                    $shard['weight']
                ));
            }
        }
        $output->writeln('');

        $workerPorts = $this->allocateWorkerPorts($workers);

        $start = microtime(true);

        /** @var array<int,Process> $running */
        $running = [];
        /** @var array<int,string> $eventFiles */
        $eventFiles = [];
        /** @var array<int,int> $eventOffsets */
        $eventOffsets = [];
        for ($i = 1; $i <= $workers; $i++) {
            if ($mode !== ShardPlanner::MODE_SHARD && ($shardAssignments[$i]['files'] ?? []) === []) {
                continue;
            }
            $xmlPath   = sprintf('%s/shard-%d.xml', $reportDir, $i);
            $eventFile = sprintf('%s/events-%d.bin', $reportDir, $i);
            touch($eventFile);
            $eventFiles[$i]   = $eventFile;
            $eventOffsets[$i] = 0;
            $ports = $workerPorts[$i];
            $env   = WorkerEnv::build($i - 1, $_ENV, getcwd() . '/tests/.env', $ports);
            $env['WPBROWSER_PARALLEL_EVENT_FILE'] = $eventFile;

            if ($mode === ShardPlanner::MODE_SHARD) {
                $shardArgs = ['--shard', "$i/$workers"];
                $suiteArgs = $testPath !== '' ? [$suite, $testPath] : [$suite];
            } else {
                $groupName = 'wpb_parallel_' . $i;
                $groupFile = sprintf('%s/shard-%d.group.txt', $reportDir, $i);
                file_put_contents($groupFile, implode("\n", $shardAssignments[$i]['files']) . "\n");
                $shardArgs = [
                    '--override', 'groups: {' . $groupName . ': ' . $groupFile . '}',
                    '--group', $groupName,
                ];
                $suiteArgs = [$suite];
            }

            $cmd = array_merge(
                [$codeceptBin, 'codeception:run'],
                $suiteArgs,
                $shardArgs,
                ['--ext', 'lucatume\\WPBrowser\\Extension\\ParallelWorkerReporter'],
                ['--xml', $xmlPath],
                ['--no-colors'],
                ['--no-artifacts'],
                WorkerEnv::overridesForWorker($i - 1, $ports),
                $passThrough
            );
            $p = new Process($cmd, $cwd, $env, null, null);
            $p->setTimeout(null);
            $p->start(static function (string $type, string $data) use ($aggregator, $i): void {
                if ($type !== Process::OUT) {
                    $aggregator->ingest($i, 'err', $data);
                }
            });
            $running[$i] = $p;
        }

        $failed    = false;
        $remaining = $running;
        while ($remaining !== []) {
            foreach ($remaining as $i => $p) {
                $this->drainEvents($eventFiles[$i], $eventOffsets, $i, $aggregator);
                if (!$p->isRunning()) {
                    if (!$p->isSuccessful()) {
                        $failed = true;
                        $aggregator->recordCrash($i, $p->getExitCode() ?? -1);
                    }
                    unset($remaining[$i]);
                }
            }
            if ($remaining !== []) {
                usleep(50_000);
            }
        }
        foreach ($eventFiles as $i => $file) {
            $this->drainEvents($file, $eventOffsets, $i, $aggregator);
            $aggregator->mergeXml(sprintf('%s/shard-%d.xml', $reportDir, $i));
        }

        $aggregator->flushSummary(microtime(true) - $start);

        $this->cleanupReportDir($reportDir);

        return ($failed || $aggregator->hasFailures()) ? 1 : 0;
    }

    /**
     * @return array<int, array<string,int>>
     */
    private function allocateWorkerPorts(int $workers): array
    {
        $preferred = [
            'WORDPRESS_LOCALHOST_PORT'    => 2389,
            'CHROMEDRIVER_PORT'           => 2390,
            'WORDPRESS_DB_LOCALHOST_PORT' => 2391,
        ];
        $reserved = [];
        $out = [];
        foreach ($preferred as $key => $base) {
            $ports = PortAllocator::allocate($workers, $base, $reserved);
            foreach ($ports as $idx => $port) {
                $workerIdx = $idx + 1;
                $out[$workerIdx][$key] = $port;
                $reserved[$port] = true;
            }
        }
        return $out;
    }

    private function resolveWorkers(string $raw): int
    {
        if ($raw === 'auto') {
            $nproc = (int)shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null') ?: 4;
            return max(1, min($nproc - 1, 8));
        }
        $n = (int)$raw;
        return $n < 1 ? 1 : $n;
    }

    /**
     * @return array<int,string>
     */
    private function buildPassThroughOptions(InputInterface $input): array
    {
        $suppress = ['workers', 'shard', 'xml', 'ext', 'no-colors', 'no-artifacts', 'override', 'group'];
        $out = [];
        foreach ($this->getDefinition()->getOptions() as $opt) {
            $name = $opt->getName();
            if (in_array($name, $suppress, true)) {
                continue;
            }
            $value = $input->getOption($name);
            if ($value === false || $value === null || $value === []) {
                continue;
            }
            if (is_bool($value)) {
                $out[] = "--{$name}";
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $v) {
                    if (!is_scalar($v)) {
                        continue;
                    }
                    $out[] = "--{$name}";
                    $out[] = (string)$v;
                }
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $out[] = "--{$name}";
            $out[] = (string)$value;
        }
        return $out;
    }

    /**
     * @param array<int,int> $offsets
     */
    private function drainEvents(string $file, array &$offsets, int $worker, DotAggregator $aggregator): void
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size === false || $size <= $offsets[$worker]) {
            return;
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return;
        }
        fseek($fh, $offsets[$worker]);
        $chunk = (string)stream_get_contents($fh);
        fclose($fh);
        $offsets[$worker] += strlen($chunk);
        if ($chunk !== '') {
            $aggregator->ingest($worker, 'out', $chunk);
        }
    }

    /**
     * @return array{0: string, 1: array<int, array{files: string[], weight: float}>}
     */
    private function resolveShardPlan(string $suite, string $testPath, int $workers, string $cwd): array
    {
        if ($testPath !== '') {
            return [ShardPlanner::MODE_SHARD, []];
        }

        $planner = new ShardPlanner();
        $weights = $planner->fromReport($cwd . '/var/_output/report.xml');
        $mode    = ShardPlanner::MODE_SHARD;

        if ($weights !== []) {
            $mode = ShardPlanner::MODE_TIMINGS;
        } else {
            $suiteRoot = $this->resolveSuiteTestsRoot($suite);
            if ($suiteRoot !== null) {
                $weights = $planner->fromTags($suiteRoot);
                if ($weights !== []) {
                    $mode = ShardPlanner::MODE_TAGS;
                }
            }
        }

        if ($weights === []) {
            return [ShardPlanner::MODE_SHARD, []];
        }
        return [$mode, $planner->plan($weights, $workers)];
    }

    private function resolveSuiteTestsRoot(string $suite): ?string
    {
        try {
            $settings = Configuration::suiteSettings($suite, Configuration::config());
        } catch (\Throwable) {
            return null;
        }
        $path = $settings['path'] ?? '';
        if (!is_string($path) || $path === '' || !is_dir($path)) {
            return null;
        }
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    private function cleanupReportDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
