<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Class Scenario
 * @package Regression
 */
abstract class Scenario
{
    /**
     * @var Client
     */
    protected Client $client;
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
     * @var callable|null
     */
    protected $beforeRequest;

    /**
     * @var callable|null
     */
    protected $afterResponse;

    /**
     * @var array
     */
    private array $vars = [];

    protected string $status = Status::UNKNOWN;

    protected ?string $conclusion;

    /**
     * Scenario constructor.
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param callable $callback
     * @return $this
     */
    public function onRequest(callable $callback): self
    {
        $this->beforeRequest = $callback;
        return $this;
    }

    /**
     * @param callable $callback
     * @return $this
     */
    public function onResponse(callable $callback): self
    {
        $this->afterResponse = $callback;
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
        if (isset($this->beforeRequest)) {
            $beforeRequest = $this->beforeRequest;
            $beforeRequest($request, $options);
        }
        $this->lastRequest = $request;
        $this->lastResponse = $this->client->send($request, $options);
        if (isset($this->afterResponse)) {
            $afterResponse = $this->afterResponse;
            $afterResponse($this->lastResponse);
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
        return $this->client->getConfig('base_uri') . '/' . $this->getLastRequest()->getRequestTarget();

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
}