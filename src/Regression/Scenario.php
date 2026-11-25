<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Regression\Client\ClientInterface;
use Regression\Client\Guzzle;

/**
 * Class Scenario
 * @package Regression
 */
abstract class Scenario
{
    /**
     * @var ClientInterface
     */
    protected ClientInterface $client;
    /**
     * @var ResponseInterface
     */
    protected ResponseInterface $lastResponse;

    /**
     * @var RequestInterface
     */
    protected RequestInterface $lastRequest;
    /**
     * @var Session|null
     */
    protected ?Session $session;

    /**
     * @var callable[]
     */
    protected array $beforeRequest = [];

    /**
     * @var callable[]
     */
    protected array $afterResponse = [];

    /**
     * @var array
     */
    protected array $vars = [];

    protected string $status = Status::UNKNOWN;

    protected ?string $conclusion;

    /**
     * Cleanup callbacks registered with defer(), executed LIFO after run().
     * @var array<int, array{0: callable, 1: string}>
     */
    private array $cleanups = [];

    /**
     * @var string[] errors raised by cleanup callbacks / tearDown()
     */
    private array $cleanupErrors = [];

    /**
     * Scenario constructor.
     */
    public function __construct(protected Config $config)
    {
    }

    /**
     * @param ClientInterface $client
     * @return $this
     */
    public function setClient(ClientInterface $client): Scenario
    {
        $this->client = $client;
        return $this;
    }

    public function getClient(): ClientInterface
    {
        if (empty($this->client)) {
            $this->client = $this->initClient();
        }
        return $this->client;
    }

    protected function initClient(): ClientInterface
    {
        $guzzle = new Client([
            'base_uri' => $this->config->getBaseUri(),
            'http_errors' => false,
            'verify' => false,
            'cookies' => true,
            // Without a timeout a single stuck request blocks a worker (and the exclusive phase) indefinitely.
            'timeout' => (float)$this->config->get('timeout', 120),
            'connect_timeout' => 15,
        ]);
        return new Guzzle($guzzle);
    }

    /**
     * @param callable $callback
     * @return $this
     */
    public function onRequest(callable ...$callback): self
    {
        foreach ($callback as $c) {
            $this->beforeRequest[] = $c;
        }
        return $this;
    }

    /**
     * @param callable $callback
     * @return $this
     */
    public function onResponse(callable ...$callback): self
    {
        foreach ($callback as $c) {
            $this->afterResponse[] = $c;
        }
        return $this;
    }

    abstract public function getDescription(): string;

    abstract public function run(): void;

    /**
     * Prepare state required by the scenario. Runs before run().
     */
    protected function setUp(): void
    {
    }

    /**
     * Revert state changed by the scenario. Always runs after run(),
     * after the callbacks registered with defer(), even if run() failed.
     */
    protected function tearDown(): void
    {
    }

    /**
     * Register a cleanup callback. Callbacks run in reverse order of registration
     * after run(), regardless of its outcome. Exceptions are collected, not rethrown.
     */
    public function defer(callable $cleanup, string $label = 'cleanup'): static
    {
        $this->cleanups[] = [$cleanup, $label];
        return $this;
    }

    /**
     * Full lifecycle: setUp() -> run() -> deferred cleanups -> tearDown().
     */
    public function execute(): void
    {
        try {
            $this->setUp();
            $this->run();
        } finally {
            $this->cleanup();
        }
    }

    protected function cleanup(): void
    {
        $cleanups = array_reverse($this->cleanups);
        $this->cleanups = [];
        if ($this->config->get('keepData', false)) {
            return;
        }
        $cleanups[] = [fn() => $this->tearDown(), 'tearDown'];
        // Cleanup must not influence the scenario's outcome or what is reported for it.
        $state = [$this->status, $this->conclusion ?? null, $this->vars, $this->lastRequest ?? null, $this->lastResponse ?? null];
        foreach ($cleanups as [$callback, $label]) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->cleanupErrors[] = "$label: " . $e->getMessage();
            }
        }
        [$this->status, $this->conclusion, $this->vars] = $state;
        if ($state[3] !== null) {
            $this->lastRequest = $state[3];
        }
        if ($state[4] !== null) {
            $this->lastResponse = $state[4];
        }
    }

    /**
     * @return string[]
     */
    public function getCleanupErrors(): array
    {
        return $this->cleanupErrors;
    }

    /**
     * Name unique for this run, so parallel/repeated runs don't collide.
     */
    public function uniqueName(string $base): string
    {
        static $counter = 0;
        // pid: parallel workers share the run ID
        return sprintf('%s_%s_%d_%d', $base, $this->config->getRunId(), getmypid(), ++$counter);
    }

    public function getClassification(): Classification
    {
        return Classification::create();
    }

    /**
     * @return string
     */
    public function getSeverity(): ?string
    {
        return null;
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        return [];
    }

    /**
     * @param RequestInterface $request
     * @return RequestInterface
     */
    protected function initSession(RequestInterface $request): RequestInterface
    {
        if (isset($this->session)) {
            return $this->session->init($request);
        }
        return $request;
    }

    /**
     * @return $this
     */
    public function logout(): self
    {
        $this->session = null;
        return $this;
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return $this
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function send(RequestInterface $request, array $options = []): self
    {
        $request = $this->initSession($request);
        if (!empty($this->beforeRequest)) {
            foreach ($this->beforeRequest as $beforeRequest) {
                $beforeRequest($request, $options);
            }
        }
        $this->lastRequest = $request;
        $this->lastResponse = $this->getClient()->send($request, $options);
        if (!empty($this->afterResponse)) {
            foreach ($this->afterResponse as $afterResponse) {
                $afterResponse($this->lastResponse);
            }
        }
        return $this;
    }

    /**
     * @param string $url
     * @param array $headers
     * @param array $options
     * @return $this
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function get(string $url, array $headers = [], array $options = []): self
    {
        $request = new Request('GET', $url, $headers);
        return $this->send($request, $options);
    }

    /**
     * @param string $url
     * @param string|resource|StreamInterface|null $body
     * @param array $headers
     * @param array $options
     * @return $this
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function post(string $url, $body, array $headers = [], array $options = []): self
    {
        $request = new Request(
            'POST',
            $url,
            $headers,
            $body
        );
        return $this->send($request, $options);
    }

    /**
     * @return string
     */
    public function getReferer(): string
    {
        if (empty($this->getLastRequest())) {
            throw new \LogicException('Referer is available only after at least on request');
        }
        return $this->getClient()->getConfig('base_uri') . '/' . $this->getLastRequest()->getRequestTarget();

    }

    /**
     * @param string $variableName
     * @return mixed|null
     */
    public function getVar(string $variableName)
    {
        return $this->vars[$variableName] ?? null;
    }

    /**
     * @return ResponseInterface
     */
    public function getLastResponse(): ResponseInterface
    {
        return $this->lastResponse;
    }

    /**
     * @return RequestInterface
     */
    public function getLastRequest(): RequestInterface
    {
        return $this->lastRequest;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    public function shouldBeExecuted(): bool
    {
        return true;
    }

    public function applyLicense(): self
    {
        return $this;
    }
}