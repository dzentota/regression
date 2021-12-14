<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

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
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;
    
    /**
     * @var array
     */
    private array $vars;

    /**
     * Scenario constructor.
     * @param Client $client
     * @param LoggerInterface $logger
     */
    public function __construct(Client $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
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
        $this->logger->debug("Expecting '$substring' in the response");
        if (false === strpos($this->lastResponse->getBody()->getContents(), $substring)) {
            $this->logger->error($errorMessage?? "The response does contain substring '$substring'");
            throw new RegressionException($this->getRegressionDescription());
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
        $this->logger->debug("Expecting response to match regexp '$regexp'");
        if (!preg_match($regexp, $this->lastResponse->getBody()->getContents())) {
            $this->logger->error($errorMessage?? "The response does match regexp '$regexp'");
            throw new RegressionException($this->getRegressionDescription());
        }
        return $this;
    }

    /**
     * @param callable $callback
     * @param string|null $errorMessage
     * @throws RegressionException
     */
    public function expect(callable $callback, ?string $errorMessage = null)
    {
        if (false === $callback($this->lastResponse)) {
            $this->logger->error($errorMessage?? 'The response does not fit expectations');
            throw new RegressionException($this->getRegressionDescription());
        }
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
        $this->logger->debug("Extracting variable from the response by regexp '$regexp'");
        if (!preg_match($regexp, $this->lastResponse->getBody()->getContents(), $m)) {
            $this->logger->error($errorMessage?? "The response does match regexp '$regexp'");
            throw new RegressionException($this->getRegressionDescription());
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
        return $this->vars[$variableName]?? null;
    }
}