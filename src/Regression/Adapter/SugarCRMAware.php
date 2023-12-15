<?php
declare(strict_types=1);

namespace Regression\Adapter;

use GuzzleHttp\Psr7\Header;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Regression\Config;
use Regression\RegressionException;
use Regression\SugarSession;

trait SugarCRMAware
{
    protected static string $serverUrl;
    protected static array $sugarVersion;

    protected ?string $minVersion = null;
    protected ?string $maxVersion = null;

    public function __construct(Config $config)
    {
        parent::__construct($config);
        $this->detectServerUrl();
        $this->detectSugarVersion();
    }

    public function loginAs(string $username): self
    {
        $password = $this->config->getUserPassword($username);
        if ($password === null) {// Password is not set in Config, trying to guess
            if (strtolower($username) === 'admin') {
                $password = 'asdf';
            } else {
                $password = $username;
            }
        }
        return $this->login($username, $password);
    }

    public function login(string $username, string $password): self
    {
        $this->apiCall('/ping?platform=base');
        if ($this->getLastResponse()->getStatusCode() === 401) {
            $pongData = json_decode((string)$this->getLastResponse()->getBody(), true);
            if (isset($pongData['url']) && str_contains($pongData['url'], 'https://sts')) {
                try {
                    $this->idmLogin($pongData['url'], $username, $password);
                } catch (\Throwable $exception) {
                    throw new \RuntimeException("Login failed");
                }
            }
        }
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

    public function idmLogin(string $url, string $username, string $password): self
    {
        $loginUrl = '';
        $stsRequest =  new Request(
            'GET',
            $url,
            ['Content-Type' => 'application/json']
        );
        $this->send($stsRequest, [
            RequestOptions::ON_STATS => function (TransferStats $stats) use (&$loginUrl){
                $loginUrl =  (string) $stats->getEffectiveUri();
                if ($stats->hasResponse()) {
                    return $stats->getResponse();
                }
            }
        ]);
        $this->extractCsrfToken();
        $this->extractRegexp('tid', '~name="tid"\s+value="(\d+)"~is');
        $payload = [
            'tid' => $this->getVar('tid'),
            'user_name' => $username,
            'password' => $password,
            'csrf_token' => $this->getVar('csrf_token')
        ];
        $tokenRequest = new Request(
            'POST',
            $loginUrl,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            http_build_query($payload)
        );
        $this->send($tokenRequest);
        $cookiesHeader = $this->getLastResponse()->getHeader('Set-Cookie');
        if (!empty($cookiesHeader)) {
            $cookies = Header::parse($cookiesHeader);
            if (!empty($cookies[0]['download_token_base'])) {
                $token = $cookies[0]['download_token_base'];
                $this->session = new SugarSession($token);
            }
        }
        return $this;
    }

    public function logout(): self
    {
        $this->apiCall('/oauth2/bwc/logout', 'POST');

        return parent::logout();
    }

    public function applyLicense(): self
    {
        $license = $this->config->getLicense();

        if ($license !== Config::DEFAULT_LICENSE) {
            return $this
                ->loginAs('admin')
                ->bwcLogin()
                ->submitForm(
                    'index.php?module=Administration&action=LicenseSettings',
                    [],
                    'index.php?module=Administration&action=LicenseSettings'
                )
                ->submitForm(
                    'index.php',
                    [
                        'module' => 'Administration',
                        'action' => 'Save',
                        'return_module' => 'Administration',
                        'return_action' => 'LicenseSettings',
                        'button' => '++Save++',
                        'license_key' => $license,
                    ],
                )
                ->submitForm(
                    'index.php',
                    [
                        'module' => 'Administration',
                        'action' => 'Save',
                        'return_module' => 'Administration',
                        'return_action' => 'LicenseSettings',
                        'button' => '++Re-validate++',
                        'license_key' => $license,
                    ],
                )
                ->logout();
        }

        return $this;
    }

    public function portalLogin(string $username, string $password): self
    {
        $payload = json_encode([
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
            'client_id' => 'support_portal',
            'platform' => 'portal',
            'client_secret' => ''
        ]);

        $tokenRequest = new Request(
            'POST',
            $this->prependBase("/oauth2/token?platform=portal"),
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
//        $this->client->getConfig('cookies')?->clear();
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
    public function submitForm(
        string $action,
        array $data,
        ?string $formUri = null,
        string $method = 'POST',
        array $headers = [],
        $options = []
    ): self {
        if ($formUri !== null) {
            $formRequest = new Request(
                'GET',
                $formUri
            );
            $this->send($formRequest)
                ->extractCsrfToken();
        }
        if (isset($this->lastRequest)) {
            $headers['Referer'] = $this->config->getBaseUri() . $this->lastRequest->getRequestTarget();
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
    public function apiCall(
        string $endpoint,
        string $method = 'GET',
        array $data = [],
        array $headers = [],
        array $options = []
    ): self {
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
        $request = new Request('GET', 'cache/config.js');
        $configResponse = $this->getClient()->send($request);
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

    protected function detectSugarVersion(): void
    {
        $request = new Request('GET', 'sugar_version.json');
        $sugarVersionResponse = $this->getClient()->send($request);
        $data = json_decode((string)$sugarVersionResponse->getBody(), true);
        if (empty($data['sugar_version'])) {
            throw new \RuntimeException('Cannot determine SugarCRM version');
        }
        static::$sugarVersion = $data;
    }

    public function shouldBeExecuted(): bool
    {
        return (is_null($this->minVersion) || self::$sugarVersion['sugar_version'] >= $this->minVersion)
            && (is_null($this->maxVersion) || self::$sugarVersion['sugar_version'] <= $this->maxVersion);
    }
}