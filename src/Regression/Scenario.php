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
     * @param string $substring
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     * @throws AssessmentException
     */
    public function expectSubstring(string $substring, ?string $errorMessage = null): self
    {
        $content = (string)$this->lastResponse->getBody();
        if (false === strpos($content, $substring)) {
            $this->throwException($errorMessage ?? "The response does contain substring '$substring'");
        }
        return $this;
    }

    /**
     * @param string $regexp
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     * @throws AssessmentException
     */
    public function expectRegexp(string $regexp, ?string $errorMessage = null): self
    {
        if (!preg_match($regexp, (string)$this->lastResponse->getBody())) {
            $this->throwException($errorMessage ?? "The response does match regexp '$regexp'");
        }
        return $this;
    }

    /**
     * @param callable $callback
     * @param string|null $errorMessage
     * @return Scenario
     * @throws AssessmentException
     * @throws RegressionException
     */
    public function expect(callable $callback, ?string $errorMessage = null): self
    {
        if (false === $callback($this->lastResponse)) {
            $this->throwException($errorMessage ?? 'The response does not fit expectations');
        }
        return $this;
    }

    /**
     * @param int $status
     * @param string|null $errorMessage
     * @return $this
     * @throws AssessmentException
     * @throws RegressionException
     */
    public function expectStatusCode(int $status, ?string $errorMessage = null): self
    {
        if ($this->lastResponse->getStatusCode() !== $status) {
            $this->throwException($errorMessage ?? sprintf('%s status code is expected, %s given', $status, $this->lastResponse->getStatusCode()));
        }
        return $this;
    }

    /**
     * @param string $variableName
     * @param string $regexp
     * @param int $group
     * @return $this
     * @throws RegressionException
     * @throws AssessmentException
     */
    public function extractRegexp(
        string $variableName,
        string $regexp,
        int $group = 1,
        ?string $errorMessage = null
    ): self {
        if (!preg_match($regexp, (string)$this->lastResponse->getBody(), $m)) {
            $this->throwException($errorMessage ?? "The response does match regexp '$regexp'");
        }
        $this->vars[$variableName] = $m[$group];
        return $this;
    }

    /**
     * @param string $variableName
     * @param callable $callback
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     * @throws AssessmentException
     */
    public function extract(
        string $variableName,
        callable $callback,
        ?string $errorMessage = null
    ): self {
        if (false === ($value = $callback($this->lastResponse))) {
            $this->throwException($errorMessage ?? 'Can not extract variable with the provided callback');
        }
        $this->vars[$variableName] = $value;
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

    /**
     * @param string $substring
     * @param $result
     * @return Scenario
     */
    public function assumeSubstring(string $substring, &$result): self
    {
        $content = (string)$this->lastResponse->getBody();
        $result =  false !== strpos($content, $substring);
        return $this;
    }

    /**
     * @param string $regexp
     * @param $result
     * @return Scenario
     */
    public function assumeRegexp(string $regexp, &$result): self
    {
        $result = (bool)preg_match($regexp, (string)$this->lastResponse->getBody());
        return $this;
    }

    /**
     * @param callable $callback
     * @param $result
     * @return Scenario
     */
    public function assume(callable $callback, &$result): self
    {
        $result = (bool)$callback($this->lastResponse);
        return $this;
    }

    /**
     * @param string $message
     * @return mixed
     * @throws AssessmentException
     * @throws RegressionException
     */
    private function throwException(string $message)
    {
        if ($this instanceof Regression) {
            throw new RegressionException($message);
        }
        throw new AssessmentException($message);
    }
}