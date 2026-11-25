<?php
declare(strict_types=1);

namespace Regression\Runner;

use Regression\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs scenarios in N worker processes.
 *
 * - Each worker logs in on its own API platform (regression_w<k>): Sugar keeps one token per
 *   user and platform, so workers sharing a platform would revoke each other's sessions.
 * - Exclusive scenarios (see Exclusivity) run afterwards in a single process, alone.
 * - The license is applied once by the runner, not by every worker.
 */
final class ParallelRunner
{
    private const BATCHES_PER_WORKER = 4;

    /** Options forwarded verbatim to worker processes. */
    private const FORWARDED_FLAGS = ['debug', 'trace', 'failed_scenarios', 'failed_steps', 'keep-data'];

    public function __construct(
        private InputInterface $input,
        private SymfonyStyle $io,
        private string $binary,
    ) {
    }

    /**
     * @param string[] $files
     * @param string[] $licenses
     */
    public function run(array $files, array $licenses, int $workers): int
    {
        $start = microtime(true);
        $exclusive = [];
        $parallel = [];
        foreach ($files as $file) {
            if (($reason = Exclusivity::reason($file)) !== null) {
                $exclusive[$file] = $reason;
            } else {
                $parallel[] = $file;
            }
        }

        $config = $this->config(Config::DEFAULT_LICENSE);
        $missing = $this->missingPlatforms($config, $workers);
        if (!empty($missing)) {
            $this->io->error(sprintf(
                "Missing API platforms for parallel workers: %s\nRun once per instance:\n  regression-platforms install %s -w %d",
                implode(', ', $missing),
                $this->input->getArgument('base_uri'),
                $workers
            ));
            return 2;
        }

        $code = 0;
        foreach ($licenses as $license) {
            $this->io->title(sprintf(
                'Running Regression Tests for license %s: %d parallel on %d workers, %d exclusive',
                $license,
                count($parallel),
                $workers,
                count($exclusive)
            ));
            if ($license !== Config::DEFAULT_LICENSE) {
                (new SugarAdminClient($this->config($license)))->applyLicense();
            }
            if ($this->io->isVerbose()) {
                foreach ($exclusive as $file => $reason) {
                    $this->io->writeln(sprintf('  exclusive: %s (%s)', basename($file), $reason));
                }
            }

            $reports = [];
            $batches = $this->batches($parallel, $workers);
            array_push($reports, ...$this->pool($batches, $workers, $license));
            if (!empty($exclusive)) {
                $this->io->section('Exclusive scenarios');
                array_push($reports, ...$this->pool([array_keys($exclusive)], 1, $license, 'base'));
            }
            $code = max($code, $this->summarize($reports));
            if ($gone = $this->missingPlatforms($this->config(Config::DEFAULT_LICENSE), $workers)) {
                // A scenario changed the API platform list without restoring it.
                $this->io->warning(sprintf(
                    "Worker API platforms disappeared during the run: %s\nA scenario must restore them in tearDown(); reinstall with:\n  regression-platforms install %s -w %d",
                    implode(', ', $gone),
                    $this->input->getArgument('base_uri'),
                    $workers
                ));
                $code = max($code, 1);
            }
        }

        $this->io->info(sprintf('Execution time: %.1f seconds.', microtime(true) - $start));
        return $code;
    }

    /**
     * Runs batches on a pool of workers. Worker k always uses platform regression_w<k>.
     *
     * @param string[][] $batches
     * @return array<int, array> worker reports
     */
    private function pool(array $batches, int $workers, string $license, ?string $platform = null): array
    {
        $queue = $batches;
        $running = [];
        $reports = [];
        $done = 0;
        $total = array_sum(array_map('count', $batches));
        $free = range(1, $workers);

        while (!empty($queue) || !empty($running)) {
            while (!empty($queue) && !empty($free)) {
                $slot = array_shift($free);
                $running[$slot] = $this->spawn(array_shift($queue), $license, $platform ?? SugarAdminClient::workerPlatform($slot), $slot, $workers);
            }
            foreach ($running as $slot => $proc) {
                if (proc_get_status($proc['handle'])['running']) {
                    continue;
                }
                proc_close($proc['handle']);
                $report = json_decode((string)@file_get_contents($proc['report']), true);
                $log = (string)@file_get_contents($proc['log']);
                if (!is_array($report)) {
                    // Worker crashed before writing a report: count all its scenarios as regressions.
                    $report = [
                        'total' => count($proc['files']), 'success' => 0, 'failed' => count($proc['files']), 'skipped' => 0,
                        'regressions' => array_map(fn($f) => basename($f) . ': worker crashed', $proc['files']),
                        'vulnerabilities' => [], 'cleanupErrors' => [], 'durations' => [],
                    ];
                    $this->io->writeln($log);
                } elseif ($report['failed'] > 0 && $this->detailsRequested()) {
                    $this->io->writeln($this->failedDetails($log));
                }
                $reports[] = $report;
                $done += count($proc['files']);
                $this->io->writeln(sprintf(
                    '[%s] %d/%d done%s',
                    $proc['platform'],
                    $done,
                    $total,
                    $report['failed'] > 0 ? sprintf(' <error>(%d failed)</error>', $report['failed']) : ''
                ));
                @unlink($proc['report']);
                @unlink($proc['log']);
                unset($running[$slot]);
                $free[] = $slot;
            }
            usleep(200_000);
        }
        return $reports;
    }

    /**
     * @param string[] $files
     */
    private function spawn(array $files, string $license, string $platform, int $worker, int $workers): array
    {
        $report = tempnam(sys_get_temp_dir(), 'regression-report-');
        $log = tempnam(sys_get_temp_dir(), 'regression-log-');
        $cmd = [PHP_BINARY, $this->binary, $this->input->getArgument('base_uri'),
            '--platform', $platform,
            '--worker', (string)$worker,
            '--workers', (string)$workers,
            '--report-json', $report,
            '--skip-license-update',
            '--slowest', '0',
            '-l', $license,
        ];
        if ($runId = $this->input->getOption('run-id')) {
            array_push($cmd, '--run-id', $runId);
        }
        foreach ($this->input->getOption('credentials') ?: [] as $credential) {
            array_push($cmd, '-c', $credential);
        }
        foreach ($this->input->getOption('user-pool') ?: [] as $poolUser) {
            array_push($cmd, '--user-pool', $poolUser);
        }
        foreach (self::FORWARDED_FLAGS as $flag) {
            if ($this->input->getOption($flag)) {
                $cmd[] = "--$flag";
            }
        }
        foreach ($files as $file) {
            array_push($cmd, '-f', $file);
        }
        $handle = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot start worker process');
        }
        return compact('handle', 'files', 'report', 'log', 'platform');
    }

    /**
     * Small batches keep workers busy until the end despite very different scenario durations.
     *
     * @param string[] $files
     * @return string[][]
     */
    private function batches(array $files, int $workers): array
    {
        if (empty($files)) {
            return [];
        }
        $size = max(1, (int)ceil(count($files) / ($workers * self::BATCHES_PER_WORKER)));
        return array_chunk($files, $size);
    }

    private function summarize(array $reports): int
    {
        $sum = fn(string $key) => array_sum(array_column($reports, $key));
        $merge = fn(string $key) => array_merge(...array_map(fn($r) => $r[$key] ?? [], $reports ?: [[]]));

        $this->io->horizontalTable(['Total', 'Success', 'Issues', 'Skipped'], [
            [$sum('total'), $sum('success'), $sum('failed'), $sum('skipped')],
        ]);
        $cleanupErrors = $merge('cleanupErrors');
        if (!empty($cleanupErrors)) {
            $this->io->warning('Cleanup problems (data may be left on the instance)');
            $this->io->listing($cleanupErrors);
        }
        $durations = $merge('durations');
        $slowest = (int)$this->input->getOption('slowest');
        if ($slowest > 0 && !empty($durations)) {
            arsort($durations);
            $rows = [];
            foreach (array_slice($durations, 0, $slowest, true) as $name => $seconds) {
                $rows[] = [sprintf('%.1fs', $seconds), mb_strimwidth((string)$name, 0, 100, '...')];
            }
            $this->io->section("Slowest $slowest scenarios");
            $this->io->table(['Time', 'Scenario'], $rows);
        }
        $code = 0;
        if ($sum('failed') === 0) {
            $this->io->success('No issues found');
        }
        if ($regressions = $merge('regressions')) {
            $this->io->error('Regression!');
            $this->io->listing($regressions);
            $code = 1;
        }
        if ($vulnerabilities = $merge('vulnerabilities')) {
            $this->io->error('Possible vulnerabilities');
            $this->io->listing($vulnerabilities);
            $code = 1;
        }
        return $code;
    }

    /**
     * Keeps the per-scenario output of a worker log, without its own summary block.
     */
    private function failedDetails(string $log): string
    {
        $cut = strpos($log, ' --------- ');
        return trim($cut === false ? $log : substr($log, 0, $cut));
    }

    private function detailsRequested(): bool
    {
        return $this->input->getOption('failed_steps') || $this->input->getOption('failed_scenarios');
    }

    /**
     * @return string[]
     */
    private function missingPlatforms(Config $config, int $workers): array
    {
        $installed = (new SugarAdminClient($config))->getCustomApiPlatforms();
        $wanted = array_map([SugarAdminClient::class, 'workerPlatform'], range(1, $workers));
        return array_values(array_diff($wanted, $installed));
    }

    private function config(string $license): Config
    {
        $credentials = [];
        foreach ($this->input->getOption('credentials') ?: [] as $credential) {
            [$login, $password] = explode(':', $credential, 2);
            $credentials[$login] = $password;
        }
        return Config::create([
            'baseUri' => $this->input->getArgument('base_uri'),
            'credentials' => $credentials,
            'license' => $license,
        ]);
    }
}
