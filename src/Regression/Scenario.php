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
            'cookies' => true
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