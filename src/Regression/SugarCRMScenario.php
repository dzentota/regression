<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Psr7;

abstract class SugarCRMScenario extends Scenario
{
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
            '/rest/v11_15/oauth2/token?platform=base',
            ['Content-Type' => 'application/json'],
            $payload
        );
        $response = $this->client->send($tokenRequest);
        if (($token = (json_decode($response->getBody()->getContents()))->access_token) == null) {
            throw new \RuntimeException("Login failed");
        }

        $sidRequest = new Psr7\Request(
            'POST',
            '/rest/v11/oauth2/bwc/login',
            ['Content-Type' => 'application/json', 'OAuth-Token' => $token],
            json_encode([])
        );
        $response = $this->client->send($sidRequest);
        if (!preg_match("/PHPSESSID=([^;]+);/", $response->getHeaderLine('Set-Cookie'), $m)) {
            throw new \RuntimeException("Session ID not found");
        }
        $this->session = new SugarSession($token);
        $this->lastResponse = $response;
        return $this;
    }

}