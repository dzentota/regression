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
     * @var array
     */
    private array $vars;

    /**
     * Scenario constructor.
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param string $username
     * @param string $password
     * @return $this
     */
    abstract public function login(string $username, string $password): self;

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
        if ($this->session !== null) {
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function send(RequestInterface $request): self
    {
        $request = $this->initSession($request);
        $this->lastResponse = $this->client->send($request);
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
        if (false === strpos($this->lastResponse->getBody()->getContents(), $substring)) {
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
        if (!preg_match($regexp, $this->lastResponse->getBody()->getContents())) {
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
            throw new RegressionException($errorMessage ?? sprintf('%s status code is expected, %s given', $status, $this->lastResponse->getStatusCode()));
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
    public function extractRegexp(string $variableName, string $regexp, int $group = 1): self
    {
        if (!preg_match($regexp, $this->lastResponse->getBody()->getContents(), $m)) {
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