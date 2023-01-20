<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

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

        $tokenRequest = new Request(
            'POST',
            $this->prependBase("/oauth2/token?platform=base"),
            ['Content-Type' => 'application/json'],
            $payload
        );
        $this->send($tokenRequest);
        if ($this->getLastResponse()->getStatusCode() !== 200 || ($token = (json_decode((string)$this->lastResponse->getBody()))->access_token) === null) {
            throw new \RuntimeException("Login failed");
        }
        $this->session = new SugarSession($token);
        return $this;
    }

    /**
     * @param string $pathToArchive
     * @return $this
     * @throws RegressionException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function uploadMLP(string $pathToArchive): self
    {
        $this->get('index.php?module=Administration&view=module&action=UpgradeWizard')
            ->extractCsrfToken();

        $headers['Referer'] = $this->getReferer();

        $multiPartStream = new MultipartStream([
            [
                'name' => 'upgrade_zip',
                'contents' => Utils::tryFopen($pathToArchive, 'r'),
            ],
            [
                'name' => 'csrf_token',
                'contents' => $this->getVar('csrf_token')
            ],
            [
                'name' => 'run',
                'contents' => 'upload'
            ],
            [
                'name' => 'upgrade_zip_escaped',
                'contents' => 'C%3A%5Cfakepath%5C' . basename($pathToArchive)
            ]
        ]);

        $mlpUpload = new Request(
            'POST',
            'index.php?module=Administration&view=module&action=UpgradeWizard',
            $headers,
            $multiPartStream
        );

        return $this->send($mlpUpload);
    }

    public function installUploadedPackage(string $id): self
    {
        $this->submitForm(
            'index.php?module=Administration&view=module&action=UpgradeWizard_prepare',
            [
                'btn_mode' => 'Install',
                'install_file' => $id,
                'mode' => 'Install'
            ]
        );

        $this->submitForm(
            'index.php?module=Administration&view=module&action=UpgradeWizard_commit',
            [
                'mode' => 'Install',
                'package_id' => $id
            ]
        );
        return $this;
    }

    /**
     * @param string $pathToArchive
     * @return void
     * @throws AssessmentException
     * @throws RegressionException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function installMLP(string $pathToArchive): self
    {
        $this->uploadMLP($pathToArchive)
            ->extractRegexp('package_id', '~var mti_data\s*=\s*\[\[".*?","(.*?)"~is')
            ->extractCsrfToken();
        return $this->installUploadedPackage($this->getVar('package_id'));
    }

    public function bwcLogin(): self
    {
        $this->client->getConfig('cookies')->clear();
        $sidRequest = new Request(
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
        $this->extract('csrf_token', function (ResponseInterface $response) {
            $content = (string)$response->getBody();
            preg_match('~name="csrf_token"\s+value="(.*?)"~is', $content, $m);
            if (!empty($m[1])) {
                return $m[1];
            }
            preg_match('~SUGAR\.csrf\.form_token = "(.*?)"~is', $content, $m);
            if (empty($m[1])) {
                return false;
            }
            return $m[1];
        }, 'Can not extract anti-CSRF token from the response');
        return $this;
    }

    /**
     * @param string $action
     * @param array $data
     * @param string|null $formUri
     * @param string $method
     * @param array $headers
     * @return $this
     * @throws RegressionException
     */
    public function submitForm(string $action, array $data, ?string $formUri = null, string $method = 'POST', array $headers = [], $options = []): self
    {
        if ($formUri !== null) {
            $formRequest = new Request(
                'GET',
                $formUri
            );
            $this->send($formRequest)
                ->extractCsrfToken();
        }
        if (isset($this->lastRequest)) {
            $headers['Referer'] = $this->client->getConfig('base_uri') . $this->lastRequest->getRequestTarget();
        }
        $request = new Request(
            $method,
            $action,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ] + $headers,
            http_build_query($data + ['csrf_token' => $this->getVar('csrf_token')])
        );
        return $this->send($request, $options);
    }

    /**
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @param array $headers
     * @param array $options
     * @return $this
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function apiCall(string $endpoint, string $method = 'GET', array $data = [], array $headers = [], array $options = []): self
    {
        $request = new Request(
            $method,
            $this->prependBase($endpoint),
            [
                'Content-Type' => 'application/json',
            ] + $headers,
            json_encode($data)
        );
        return $this->send($request, $options);
    }

    /**
     */
    protected function detectServerUrl(): void
    {
        $configResponse = $this->client->get('cache/config.js');
        preg_match('~"serverUrl":"(.*?)"~is', (string)$configResponse->getBody(), $m);
        if (empty($m[1])) {
            throw new \RuntimeException('Cannot determine REST API version');
        }
        static::$serverUrl = stripslashes($m[1]);
    }

    protected function prependBase(string $endpoint): string
    {
        return static::$serverUrl . $endpoint;
    }

}