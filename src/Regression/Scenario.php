<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    /**
     * @return string
     */
    abstract public function getRegressionDescription(): string;

    abstract public function run(): void;

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
     * @return $this
     */
    public function send(RequestInterface $request): self
    {
        $request = $this->initSession($request);
        if (isset($this->beforeRequest)) {
            $beforeRequest = $this->beforeRequest;
            $beforeRequest($request);
        }
        $this->lastResponse = $this->client->send($request);
        if (isset($this->afterResponse)) {
            $afterResponse = $this->afterResponse;
            $afterResponse($this->lastResponse);
        }
        return $this;
    }

    /**
     * @param string $substring
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function expectSubstring(string $substring, ?string $errorMessage = null): self
    {
        $content = (string)$this->lastResponse->getBody();
        if (false === strpos($content, $substring)) {
            throw new RegressionException($errorMessage ?? "The response does contain substring '$substring'");
        }
        return $this;
    }

    /**
     * @param string $regexp
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function expectRegexp(string $regexp, ?string $errorMessage = null): self
    {
        if (!preg_match($regexp, (string)$this->lastResponse->getBody())) {
            throw new RegressionException($errorMessage ?? "The response does match regexp '$regexp'");
        }
        return $this;
    }

    /**
     * @param callable $callback
     * @param string|null $errorMessage
     * @throws RegressionException
     */
    public function expect(callable $callback, ?string $errorMessage = null): self
    {
        if (false === $callback($this->lastResponse)) {
            throw new RegressionException($errorMessage ?? 'The response does not fit expectations');
        }
        return $this;
    }

    public function expectStatusCode(int $status, ?string $errorMessage = null): self
    {
        if ($this->lastResponse->getStatusCode() !== $status) {
            throw new RegressionException($errorMessage ?? sprintf('%s status code is expected, %s given', $status,
                    $this->lastResponse->getStatusCode()));
        }
        return $this;
    }

    /**
     * @param string $variableName
     * @param string $regexp
     * @param int $group
     * @return $this
     * @throws RegressionException
     */
    public function extractRegexp(string $variableName, string $regexp, int $group = 1, ?string $errorMessage = null): self
    {
        if (!preg_match($regexp, (string) $this->lastResponse->getBody(), $m)) {
            throw new RegressionException($errorMessage ?? "The response does match regexp '$regexp'");
        }
        $this->vars[$variableName] = $m[$group];
        return $this;
    }

    /**
     * @param string $variableName
     * @return mixed|null
     */
    public function getVar(string $variableName)
    {
        return $this->vars[$variableName] ?? null;
    }
}