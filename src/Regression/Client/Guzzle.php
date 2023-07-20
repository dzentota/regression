<?php

namespace Regression\Client;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Guzzle implements ClientInterface
{
    private GuzzleClientInterface $httpClient;

    public function __construct(GuzzleClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function getHttpClient(): GuzzleClientInterface
    {
        return $this->httpClient;
    }

    public function setHttpClient(GuzzleClientInterface $httpClient): Guzzle
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->getHttpClient()->send($request, $options);
    }

    public function getConfig($option = null)
    {
        return $this->getHttpClient()->getConfig($option);
    }
}