<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;

abstract class SugarCRMScenario extends Scenario
{
    protected static string $serverUrl;

    public function __construct(Client $client)
    {
        parent::__construct($client);
        $this->detectServerUrl();
    }

    public function login(string $username, string $password): self
    {
        $this->client->getConfig('cookies')->clear();
        $payload = json_encode([
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
            'client_id' => 'sugar',
            'platform' => 'base',
            'client_secret' => ''
        ]);

        $tokenRequest = new Psr7\Request(
            'POST',
            $this->prependBase("/oauth2/token?platform=base"),
            ['Content-Type' => 'application/json'],
            $payload
        );
        if (isset($this->beforeRequest)) {
            $beforeRequest = $this->beforeRequest;
            $beforeRequest($tokenRequest);
        }
        $response = $this->client->send($tokenRequest);
        if (isset($this->afterResponse)) {
            $afterResponse = $this->afterResponse;
            $afterResponse(clone $response);
        }

        if (($token = (json_decode((string)$response->getBody()))->access_token) === null) {
            throw new \RuntimeException("Login failed");
        }

        $sidRequest = new Psr7\Request(
            'POST',
            $this->prependBase('/oauth2/bwc/login'),
            ['Content-Type' => 'application/json', 'OAuth-Token' => $token],
            json_encode([])
        );
        if (isset($this->beforeRequest)) {
            $beforeRequest = $this->beforeRequest;
            $beforeRequest($sidRequest);
        }
        $response = $this->client->send($sidRequest);
        if (isset($this->afterResponse)) {
            $afterResponse = $this->afterResponse;
            $afterResponse($response);
        }
        if (!preg_match("/PHPSESSID=([^;]+);/", $response->getHeaderLine('Set-Cookie'), $m)) {
        //    throw new \RuntimeException("Session ID not found");
        }
        $this->session = new SugarSession($token);
        $this->lastResponse = $response;
        return $this;
    }

    /**
     */
    protected function detectServerUrl(): void
    {
        $configResponse = $this->client->get('/cache/config.js');
        preg_match('~"serverUrl":"(.*?)"~is', (string)$configResponse->getBody(), $m);
        if (empty($m[1])) {
            throw new \RuntimeException('Cannot determine REST API version');
        }
        static::$serverUrl = stripslashes($m[1]);
    }

    protected function prependBase(string $endpoint): string
    {
        return '/' .static::$serverUrl . $endpoint;
    }

}