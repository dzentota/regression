<?php
declare(strict_types=1);

namespace Regression\Runner;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Regression\Config;
use Regression\Helpers\Output\QueueOutput;
use Regression\Helpers\Output\RequestOutputItem;
use Regression\Helpers\Output\ResponseOutputItem;
use Regression\Scenario;
use Regression\Status;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs one scenario file and returns its result. Shared by the serial runner and parallel workers.
 */
final class ScenarioExecutor
{
    public const SUCCESS = 'success';
    public const VULNERABILITY = 'vulnerability';
    public const REGRESSION = 'regression';
    public const RISKY = 'risky';
    public const SKIPPED = 'skipped';
    public const MISSING = 'missing';

    public function __construct(
        private bool $debug = false,
        private bool $failedScenarios = false,
        private bool $failedSteps = false,
        private bool $trace = false,
    ) {
    }

    /**
     * @return array{file: string, description: string, outcome: string, message: ?string, cleanupErrors: string[], duration: float}
     */
    public function run(string $file, Config $config, SymfonyStyle $io): array
    {
        $result = ['file' => $file, 'description' => basename($file, '.php'), 'outcome' => self::MISSING,
            'message' => null, 'cleanupErrors' => [], 'duration' => 0.0];
        if (!file_exists($file)) {
            $io->error("$file doesn't exist. Skipped");
            return $result;
        }
        require_once $file;
        $class = basename($file, '.php');
        $start = microtime(true);
        $queueOutput = new QueueOutput();
        $details = $this->debug || $this->failedScenarios || $this->failedSteps;

        try {
            /** @var Scenario $scenario */
            $scenario = new $class($config);
        } catch (\Throwable $e) {
            $result['outcome'] = self::REGRESSION;
            $result['message'] = sprintf('%s: "cannot initialize: %s"', $class, $e->getMessage());
            $result['duration'] = microtime(true) - $start;
            return $result;
        }
        $result['description'] = $scenario->getDescription();

        if (!$scenario->shouldBeExecuted()) {
            $io->newLine(2);
            $io->title($scenario->getDescription() . ': SKIPPED');
            $result['outcome'] = self::SKIPPED;
            return $result;
        }
        if ($this->trace) {
            $scenario->onRequest(function (RequestInterface &$request) use ($scenario) {
                $request = $request->withHeader('X-Test-Name', get_class($scenario));
            });
        }
        if ($details) {
            $scenario
                ->onRequest(function (RequestInterface $request, array $options = []) use ($io, $queueOutput) {
                    $item = new RequestOutputItem($request, $options);
                    if ($this->debug) {
                        $item->execOutput($io)();
                        return;
                    }
                    if ($this->failedSteps) {
                        $queueOutput->clear();
                    }
                    $queueOutput->push($item);
                })
                ->onResponse(function (ResponseInterface $response) use ($io, $queueOutput) {
                    $item = new ResponseOutputItem($response);
                    if ($this->debug) {
                        $item->execOutput($io)();
                        return;
                    }
                    $queueOutput->push($item);
                });
            $io->title($scenario->getDescription());
        }

        $label = fn(string $message) => $scenario->getSeverity()
            ? sprintf('[%s] %s: "%s"', $scenario->getSeverity(), $scenario->getDescription(), $message)
            : sprintf('%s: "%s"', $scenario->getDescription(), $message);
        try {
            $scenario->applyLicense()->execute();
            if ($scenario->getStatus() === Status::UNKNOWN) {
                $result['outcome'] = self::RISKY;
                $result['message'] = $scenario->getDescription() . ': "Risky. No expectations or assumptions made"';
                $io->error("Risky. No expectations or assumptions made: {$scenario->getDescription()}");
            } elseif ($scenario->getStatus() === Status::HAS_ISSUE) {
                $result['outcome'] = self::VULNERABILITY;
                $result['message'] = $label((string)$scenario->getConclusion());
            } else {
                $result['outcome'] = self::SUCCESS;
            }
        } catch (\Throwable $exception) {
            $result['outcome'] = self::REGRESSION;
            $result['message'] = $label($exception->getMessage());
        } finally {
            $result['duration'] = microtime(true) - $start;
            foreach ($scenario->getCleanupErrors() as $cleanupError) {
                $result['cleanupErrors'][] = sprintf('%s: %s', $scenario->getDescription(), $cleanupError);
            }
            $failed = in_array($result['outcome'], [self::REGRESSION, self::VULNERABILITY, self::RISKY], true);
            if ($this->failedScenarios || $this->failedSteps) {
                if ($failed) {
                    $io->error('FAILED');
                    $queueOutput->flushToOutput($io);
                } else {
                    $io->success('PASSED');
                }
            }
            $queueOutput->clear();
        }
        return $result;
    }
}
