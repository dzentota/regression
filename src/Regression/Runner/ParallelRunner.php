<?php
declare(strict_types=1);

namespace Regression\Runner;

use Regression\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs scenarios on N long-lived worker processes (`regression --serve`), one scenario at a time per
 * worker, scheduling by resource locks (see Locks):
 *
 * - Worker k logs in on API platform regression_w<k>: Sugar keeps one token per user and platform, so
 *   workers sharing a platform would revoke each other's sessions.
 * - A scenario starts only when its locks don't conflict with running scenarios (e.g. two scenarios
 *   changing sarah's roles never overlap; scenarios only logging in as sarah do).
 * - Exclusive scenarios (write lock '*') run at the end, one by one, with nothing else running.
 * - Longest scenarios first when timings from a previous --report-json are given (--timings).
 * - The license is applied once by the runner, not by every worker.
 */
final class ParallelRunner
{
    /** Options forwarded verbatim to worker processes. */
    private const FORWARDED_FLAGS = ['debug', 'trace', 'failed_scenarios', 'failed_steps', 'keep-data'];

    /** @var array<int, array> */
    private array $workers = [];

    /** @var array<string, int> */
    private array $readers = [];

    /** @var array<string, true> */
    private array $writers = [];

    private int $done = 0;
    private int $total = 0;

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
        $timings = $this->previousTimings();
        $shared = [];
        $exclusive = [];
        foreach ($files as $file) {
            $locks = Locks::detect($file);
            $job = ['file' => $file, 'locks' => $locks, 'expected' => $timings[$file] ?? 0.0];
            if ($locks->isExclusive()) {
                $exclusive[] = $job;
            } else {
                $shared[] = $job;
            }
        }
        // Longest first; without timings, scenarios with write locks first (they constrain scheduling most).
        $order = fn(array $a, array $b) => [$b['expected'], count($b['locks']->write)] <=> [$a['expected'], count($a['locks']->write)];
        usort($shared, $order);
        usort($exclusive, $order);

        $missing = $this->missingPlatforms($workers);
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
        $allResults = [];
        foreach ($licenses as $license) {
            $this->io->title(sprintf(
                'Running Regression Tests for license %s: %d shared on %d workers, then %d exclusive',
                $license,
                count($shared),
                $workers,
                count($exclusive)
            ));
            if ($this->io->isVerbose()) {
                foreach ([...$shared, ...$exclusive] as $job) {
                    if ($job['locks']->reason !== '') {
                        $this->io->writeln(sprintf('  %s: %s', basename($job['file']), $job['locks']->reason));
                    }
                }
            }
            if ($license !== Config::DEFAULT_LICENSE) {
                (new SugarAdminClient($this->config($license)))->applyLicense();
            }

            $this->done = 0;
            $this->total = count($shared) + count($exclusive);
            $phase = microtime(true);
            $results = $this->schedule($shared, $workers, $license);
            $this->io->info(sprintf('Shared phase: %.1f seconds.', microtime(true) - $phase));

            // A failure in the shared phase may come from interference with a concurrent scenario whose side
            // effects weren't detected: re-run it alone and trust that result.
            $retry = [];
            foreach ($results as $i => $result) {
                if ($this->isFailure($result)) {
                    $retry[] = ['file' => $result['file'], 'locks' => new Locks([], [Locks::ALL], 'retry alone'), 'expected' => 0.0, 'retryOf' => $result];
                    unset($results[$i]);
                }
            }
            $results = array_values($results);
            $this->total += count($retry);

            if (!empty($exclusive) || !empty($retry)) {
                $this->io->section(sprintf('Exclusive scenarios (%d) and retries of shared failures (%d)', count($exclusive), count($retry)));
                $phase = microtime(true);
                $byFile = array_column($retry, 'retryOf', 'file');
                $interference = [];
                foreach ($this->schedule([...$retry, ...$exclusive], 1, $license) as $result) {
                    if (isset($byFile[$result['file']])) {
                        if (!$this->isFailure($result)) {
                            $interference[] = sprintf('%s (failed in parallel: %s)', $result['description'], $byFile[$result['file']]['message'] ?? $byFile[$result['file']]['outcome']);
                            $result['flaky'] = true;
                        }
                        $result['retried'] = true;
                    }
                    $results[] = $result;
                }
                $this->io->info(sprintf('Exclusive phase: %.1f seconds.', microtime(true) - $phase));
                if ($interference) {
                    $this->io->warning("Failed in parallel, passed alone (undetected interference: add #[Uses]/#[Exclusive] to the culprit):");
                    $this->io->listing($interference);
                }
            }
            $code = max($code, $this->summarize($results));
            $allResults[$license] = $results;

            if ($gone = $this->missingPlatforms($workers)) {
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
        $this->writeReport($allResults);
        $this->io->info(sprintf('Execution time: %.1f seconds.', microtime(true) - $start));
        return $code;
    }

    /**
     * @param array<int, array{file: string, locks: Locks, expected: float}> $queue
     * @return array<int, array> results
     */
    private function schedule(array $queue, int $workers, string $license): array
    {
        $results = [];
        $this->readers = [];
        $this->writers = [];
        for ($k = 1; $k <= $workers; $k++) {
            $this->workers[$k] = $this->spawn($k, $workers, $license);
        }
        try {
            while (!empty($queue) || $this->busy()) {
                foreach ($this->workers as $k => $worker) {
                    if ($worker['job'] !== null) {
                        continue;
                    }
                    foreach ($queue as $i => $job) {
                        if ($job['locks']->canRun($this->readers, $this->writers)) {
                            unset($queue[$i]);
                            $this->dispatch($k, $job);
                            break;
                        }
                    }
                }
                array_push($results, ...$this->collect($workers, $license));
            }
        } finally {
            foreach ($this->workers as $worker) {
                @fclose($worker['stdin']);
                @fclose($worker['stdout']);
                proc_close($worker['handle']);
                @unlink($worker['log']);
            }
            $this->workers = [];
        }
        return $results;
    }

    private function dispatch(int $k, array $job): void
    {
        foreach ($job['locks']->read as $resource) {
            $this->readers[$resource] = ($this->readers[$resource] ?? 0) + 1;
        }
        foreach ($job['locks']->write as $resource) {
            $this->writers[$resource] = true;
        }
        $this->workers[$k]['job'] = $job;
        fwrite($this->workers[$k]['stdin'], $job['file'] . "\n");
        fflush($this->workers[$k]['stdin']);
    }

    private function release(array $job): void
    {
        foreach ($job['locks']->read as $resource) {
            if (--$this->readers[$resource] <= 0) {
                unset($this->readers[$resource]);
            }
        }
        foreach ($job['locks']->write as $resource) {
            unset($this->writers[$resource]);
        }
    }

    /**
     * Waits briefly for worker output and returns completed results.
     */
    private function collect(int $workers, string $license): array
    {
        $streams = array_values(array_map(fn($w) => $w['stdout'], array_filter($this->workers, fn($w) => $w['job'] !== null)));
        if (empty($streams)) {
            return [];
        }
        $write = $except = null;
        if (@stream_select($streams, $write, $except, 0, 200_000) === false) {
            return [];
        }
        $results = [];
        foreach (array_keys($this->workers) as $k) {
            if ($this->workers[$k]['job'] === null) {
                continue;
            }
            $chunk = fread($this->workers[$k]['stdout'], 1 << 20);
            if ($chunk !== false && $chunk !== '') {
                $this->workers[$k]['buffer'] .= $chunk;
            }
            while (($pos = strpos($this->workers[$k]['buffer'], "\n")) !== false) {
                $line = substr($this->workers[$k]['buffer'], 0, $pos);
                $this->workers[$k]['buffer'] = substr($this->workers[$k]['buffer'], $pos + 1);
                $result = json_decode($line, true);
                if (is_array($result) && $this->workers[$k]['job'] !== null) {
                    $results[] = $this->finish($k, $result);
                }
            }
            if ($this->workers[$k]['job'] !== null && !proc_get_status($this->workers[$k]['handle'])['running']) {
                // Worker died mid-scenario: report it and start a fresh one.
                $file = $this->workers[$k]['job']['file'];
                $results[] = $this->finish($k, [
                    'file' => $file, 'description' => basename($file, '.php'),
                    'outcome' => ScenarioExecutor::REGRESSION,
                    'message' => basename($file, '.php') . ': "worker process crashed"',
                    'cleanupErrors' => [], 'duration' => 0.0, 'output' => $this->workerLog($this->workers[$k]),
                ]);
                proc_close($this->workers[$k]['handle']);
                @unlink($this->workers[$k]['log']);
                $this->workers[$k] = $this->spawn($k, $workers, $license);
            }
        }
        return $results;
    }

    private function isFailure(array $result): bool
    {
        return in_array($result['outcome'], [ScenarioExecutor::REGRESSION, ScenarioExecutor::VULNERABILITY, ScenarioExecutor::RISKY], true);
    }

    private function finish(int $k, array $result): array
    {
        $this->release($this->workers[$k]['job']);
        $this->workers[$k]['job'] = null;
        $this->done++;
        $failed = in_array($result['outcome'], [ScenarioExecutor::REGRESSION, ScenarioExecutor::VULNERABILITY, ScenarioExecutor::RISKY], true);
        $this->io->writeln(sprintf(
            '[w%d] %3d/%d %s %5.1fs %s',
            $k,
            $this->done,
            $this->total,
            match ($result['outcome']) {
                ScenarioExecutor::SUCCESS => '<info>ok  </info>',
                ScenarioExecutor::SKIPPED => 'skip',
                default => '<error>FAIL</error>',
            },
            $result['duration'],
            mb_strimwidth((string)$result['description'], 0, 90, '...')
        ));
        if ($failed && $this->detailsRequested() && !empty($result['output'])) {
            $this->io->writeln(trim($result['output']));
        }
        unset($result['output']);
        return $result;
    }

    private function spawn(int $worker, int $workers, string $license): array
    {
        $log = tempnam(sys_get_temp_dir(), 'regression-worker-');
        $cmd = [PHP_BINARY, $this->binary, $this->input->getArgument('base_uri'),
            '--serve',
            '--platform', SugarAdminClient::workerPlatform($worker),
            '--worker', (string)$worker,
            '--workers', (string)$workers,
            '--skip-license-update',
            '--timeout', (string)$this->input->getOption('timeout'),
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
        $handle = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot start worker process');
        }
        stream_set_blocking($pipes[1], false);
        return ['handle' => $handle, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'buffer' => '', 'job' => null, 'log' => $log];
    }

    private function workerLog(array $worker): string
    {
        $log = (string)@file_get_contents($worker['log']);
        return trim($worker['buffer'] . "\n" . substr($log, -4000));
    }

    private function busy(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker['job'] !== null) {
                return true;
            }
        }
        return false;
    }

    private function summarize(array $results): int
    {
        $count = fn(string ...$outcomes) => count(array_filter($results, fn($r) => in_array($r['outcome'], $outcomes, true)));
        $messages = fn(string $outcome) => array_values(array_map(fn($r) => $r['message'], array_filter($results, fn($r) => $r['outcome'] === $outcome)));
        $failed = $count(ScenarioExecutor::REGRESSION, ScenarioExecutor::VULNERABILITY);

        $this->io->horizontalTable(['Total', 'Success', 'Issues', 'Skipped'], [
            [count($results), $count(ScenarioExecutor::SUCCESS), $failed, $count(ScenarioExecutor::SKIPPED)],
        ]);
        $cleanupErrors = array_merge([], ...array_map(fn($r) => $r['cleanupErrors'], $results));
        if (!empty($cleanupErrors)) {
            $this->io->warning('Cleanup problems (data may be left on the instance)');
            $this->io->listing($cleanupErrors);
        }
        $slowest = (int)$this->input->getOption('slowest');
        if ($slowest > 0 && !empty($results)) {
            $sorted = $results;
            usort($sorted, fn($a, $b) => $b['duration'] <=> $a['duration']);
            $rows = array_map(
                fn($r) => [sprintf('%.1fs', $r['duration']), mb_strimwidth((string)$r['description'], 0, 100, '...')],
                array_slice($sorted, 0, $slowest)
            );
            $this->io->section("Slowest $slowest scenarios");
            $this->io->table(['Time', 'Scenario'], $rows);
        }
        $code = 0;
        if ($failed === 0) {
            $this->io->success('No issues found');
        }
        if ($regressions = $messages(ScenarioExecutor::REGRESSION)) {
            $this->io->error('Regression!');
            $this->io->listing($regressions);
            $code = 1;
        }
        if ($vulnerabilities = $messages(ScenarioExecutor::VULNERABILITY)) {
            $this->io->error('Possible vulnerabilities');
            $this->io->listing($vulnerabilities);
            $code = 1;
        }
        return $code;
    }

    /**
     * @param array<string, array> $resultsByLicense
     */
    private function writeReport(array $resultsByLicense): void
    {
        if (!$file = $this->input->getOption('report-json')) {
            return;
        }
        $licenses = [];
        foreach ($resultsByLicense as $license => $results) {
            $licenses[] = ['license' => $license, 'tests' => $results];
        }
        file_put_contents($file, json_encode(['licenses' => $licenses], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @return array<string, float> file => seconds, from a previous --report-json (serial or parallel)
     */
    private function previousTimings(): array
    {
        $file = $this->input->getOption('timings');
        if (!$file || !is_file($file)) {
            return [];
        }
        $report = json_decode((string)file_get_contents($file), true) ?: [];
        $tests = $report['tests'] ?? array_merge([], ...array_map(fn($l) => $l['tests'] ?? [], $report['licenses'] ?? []));
        $timings = [];
        foreach ($tests as $test) {
            $timings[$test['file']] = max($timings[$test['file']] ?? 0, (float)$test['duration']);
        }
        return $timings;
    }

    private function detailsRequested(): bool
    {
        return $this->input->getOption('failed_steps') || $this->input->getOption('failed_scenarios') || $this->input->getOption('debug');
    }

    /**
     * @return string[]
     */
    private function missingPlatforms(int $workers): array
    {
        $installed = (new SugarAdminClient($this->config(Config::DEFAULT_LICENSE)))->getCustomApiPlatforms();
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
