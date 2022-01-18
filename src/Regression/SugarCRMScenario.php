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
        $this->send($tokenRequest);
        if (($token = (json_decode((string)$this->lastResponse->getBody()))->access_token) === null) {
            throw new \RuntimeException("Login failed");
        }
        $this->session = new SugarSession($token);
        return $this;
    }

    public function bwcLogin(): self
    {
        $this->client->getConfig('cookies')->clear();
        $sidRequest = new Psr7\Request(
            'POST',
            $this->prependBase('/oauth2/bwc/login'),
            ['Content-Type' => 'application/json'],
            json_encode([])
        );
        $this->send($sidRequest);
        if (!preg_match("/PHPSESSID=([^;]+);/", $this->lastResponse->getHeaderLine('Set-Cookie'), $m)) {
            throw new \RuntimeException("Session ID not found");
        }
        return $this;
    }

    /**
     * Extracts anti-CSRF token from the last response to `csrf_token` variable
     * @throws RegressionException
     */
    public function extractCsrfToken(): self
    {
        return $this->extractRegexp('csrf_token', '~name="csrf_token"\s+value="(.*?)"~is', 1, 'Can not extract anti-CSRF token from the response');
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