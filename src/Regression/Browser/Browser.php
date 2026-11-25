<?php
declare(strict_types=1);

namespace Regression\Browser;

/**
 * Client for the Playwright helper (resources/browser/regression-browser.mjs).
 * One Node process per PHP process, started lazily and reused across scenarios.
 *
 * Requirements (only where browser-based scenarios run): Node.js >= 18 and, in resources/browser:
 *   npm install && npx playwright install chromium
 * or set REGRESSION_BROWSER_HELPER to a custom helper path.
 */
final class Browser
{
    private static ?self $instance = null;

    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    private int $nextId = 1;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function helperPath(): string
    {
        return getenv('REGRESSION_BROWSER_HELPER') ?: dirname(__DIR__, 3) . '/resources/browser/regression-browser.mjs';
    }

    /**
     * @param array{
     *   cookies?: array<int, array{name: string, value: string, domain: string, path?: string, secure?: bool, httpOnly?: bool}>,
     *   localStorage?: array<string, string>,
     *   headers?: array<string, string>,
     *   markers?: string[],
     *   waitUntil?: string,
     *   timeoutMs?: int,
     *   settleMs?: int,
     *   actions?: array<int, array<string, string>>,
     * } $options
     * @return array{ok: bool, status: ?int, finalUrl: string, hits: array, unmatched: array, pageErrors: array, console: array, error?: string}
     */
    public function visit(string $url, array $options = []): array
    {
        $this->start();
        $id = $this->nextId++;
        fwrite($this->stdin, json_encode(['id' => $id, 'cmd' => 'visit', 'url' => $url] + $options) . "\n");
        fflush($this->stdin);

        // navigation + Sidecar boot (possibly twice: reload after a metadata change) + actions + settle
        $timeout = (($options['timeoutMs'] ?? 30000) * 2 + ($options['sidecarTimeoutMs'] ?? 45000) * 2
            + ($options['settleMs'] ?? 1500)) / 1000 + 30;
        $deadline = microtime(true) + $timeout;
        $line = '';
        while (!str_ends_with($line, "\n")) {
            if (microtime(true) > $deadline) {
                $this->stop();
                throw new BrowserException("Browser helper did not answer within {$timeout}s for $url");
            }
            $read = [$this->stdout];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) > 0) {
                $chunk = fgets($this->stdout);
                if ($chunk === false) {
                    $this->stop();
                    throw new BrowserException('Browser helper exited unexpectedly');
                }
                $line .= $chunk;
            }
        }
        $response = json_decode($line, true);
        if (!is_array($response)) {
            throw new BrowserException('Invalid answer from browser helper: ' . substr($line, 0, 300));
        }
        if (!empty($response['fatal'])) {
            $this->stop();
            throw new BrowserException($response['error'] ?? 'Browser helper failed');
        }
        if (empty($response['ok'])) {
            throw new BrowserException("Browser could not load $url: " . ($response['error'] ?? 'unknown error'));
        }
        return $response;
    }

    private function start(): void
    {
        if ($this->process !== null && proc_get_status($this->process)['running']) {
            return;
        }
        $helper = self::helperPath();
        if (!is_file($helper)) {
            throw new BrowserException("Browser helper not found: $helper");
        }
        $node = getenv('REGRESSION_NODE') ?: 'node';
        $process = proc_open([$node, $helper], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, dirname($helper));
        if (!is_resource($process)) {
            throw new BrowserException("Cannot start browser helper with `$node` (Node.js >= 18 required)");
        }
        [$this->process, $this->stdin, $this->stdout] = [$process, $pipes[0], $pipes[1]];
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        @fwrite($this->stdin, json_encode(['cmd' => 'close']) . "\n");
        @fclose($this->stdin);
        @fclose($this->stdout);
        proc_close($this->process);
        $this->process = $this->stdin = $this->stdout = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
