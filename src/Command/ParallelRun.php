<?php

namespace lucatume\WPBrowser\Command;

use Codeception\Command\Run;
use Codeception\CustomCommandInterface;
use lucatume\WPBrowser\Adapters\Symfony\Component\Process\Process;
use lucatume\WPBrowser\Command\ParallelRun\DotAggregator;
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
        $output->writeln('');

        $start = microtime(true);

        /** @var array<int,Process> $running */
        $running = [];
        /** @var array<int,string> $eventFiles */
        $eventFiles = [];
        /** @var array<int,int> $eventOffsets */
        $eventOffsets = [];
        for ($i = 1; $i <= $workers; $i++) {
            $xmlPath   = sprintf('%s/shard-%d.xml', $reportDir, $i);
            $eventFile = sprintf('%s/events-%d.bin', $reportDir, $i);
            touch($eventFile);
            $eventFiles[$i]   = $eventFile;
            $eventOffsets[$i] = 0;
            $env = WorkerEnv::build($i - 1, $_ENV, getcwd() . '/tests/.env');
            $env['WPBROWSER_PARALLEL_EVENT_FILE'] = $eventFile;
            $cmd = array_merge(
                [$codeceptBin, 'codeception:run', $suite],
                $testPath !== '' ? [$testPath] : [],
                ['--shard', "$i/$workers"],
                ['--ext', 'lucatume\\WPBrowser\\Extension\\ParallelWorkerReporter'],
                ['--xml', $xmlPath],
                ['--no-colors'],
                ['--no-artifacts'],
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
        $suppress = ['workers', 'shard', 'xml', 'ext', 'no-colors', 'no-artifacts'];
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
