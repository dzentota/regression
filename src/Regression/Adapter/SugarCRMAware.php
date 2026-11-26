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
use Regression\Adapter\SugarCRM\ACL\ModulePermissions;
use Regression\Config;
use Regression\Helpers\Client\ChromeClientOptions;
use Regression\Helpers\MLPBuilder;
use Regression\RegressionException;
use Regression\SugarSession;
use Regression\Adapter\SugarCRM\Role;

trait SugarCRMAware
{
    use \Regression\Adapter\SugarCRM\ManagesUsers;

    protected static string $serverUrl;
    protected static array $sugarVersion;

    protected static string $subscriptionProduct;

    /** @var string[]|null license types available on the instance (GET /me as admin: current_user.licenses) */
    protected static ?array $instanceLicenses = null;

    private static bool $serverInfoReceived = false;
    private static bool $licenseUpdated = false;

    /** null until detected by login()/isIdmMode() */
    protected static ?bool $idmMode = null;

    /** @var array{0: string, 1: string}|null [username, password] of the current session */
    protected ?array $currentUser = null;

    /** session object created by the last login(); any other session (portal, logout...) is unknown */
    private ?object $currentUserSession = null;

    /** @var array<string, true> users whose preferences were already snapshotted */
    private array $preferencesSnapshotted = [];

    protected ?string $minVersion = null;
    protected ?string $maxVersion = null;

    public function __construct(Config $config)
    {
        parent::__construct($config);

        if (!static::$serverInfoReceived) {
            $this->detectServerUrl();
            $this->detectSugarVersion();
            $this->detectSugarSubscription();
            $this->detectInstanceLicenses();

            static::$serverInfoReceived = true;
        }
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
        $platform = $this->config->getPlatform();
        $this->apiCall('/ping?platform=' . rawurlencode($platform));
        if ($this->getLastResponse()->getStatusCode() === 401) {
            $pongData = json_decode((string)$this->getLastResponse()->getBody(), true);
            if ($this->isIdmPong($pongData)) {
                static::$idmMode = true;
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
            'platform' => $platform,
            'client_secret' => ''
        ]);

        $tokenRequest = new Request(
            'POST',
            $this->prependBase('/oauth2/token?platform=' . rawurlencode($platform)),
            ['Content-Type' => 'application/json'],
            $payload
        );
        $this->send($tokenRequest);
        $tokens = json_decode((string)$this->lastResponse->getBody());
        if ($this->getLastResponse()->getStatusCode() !== 200 || ($token = $tokens->access_token ?? null) === null) {
            throw new \RuntimeException("Login failed");
        }
        $this->session = new SugarSession($token, $tokens->refresh_token ?? null);
        $this->currentUser = [$username, $password];
        $this->currentUserSession = $this->session;
        return $this;
    }

    public function idmLogin(string $url, string $username, string $password): self
    {
        $loginUrl = '';
        $stsRequest = new Request(
            'GET',
            $url,
            ['Content-Type' => 'application/json']
        );
        $this->send($stsRequest, [
            RequestOptions::ON_STATS => function (TransferStats $stats) use (&$loginUrl) {
                $loginUrl = (string)$stats->getEffectiveUri();
                if ($stats->hasResponse()) {
                    return $stats->getResponse();
                }
            }
        ]);
        $this->extractRegexp('csrf_token', '~name="token"\svalue\s*=\s*"(.*?)"~is');
        $this->extractRegexp('tid', '~name="tenant_hint"\s+value="(\d+)"~is');
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

        if ($license !== Config::DEFAULT_LICENSE && !self::$licenseUpdated && !$this->config->get('licenseApplied', false)) {
            $this
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

            self::$licenseUpdated = true;
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
        $this->submitForm('index.php?module=Administration&view=module&action=UpgradeWizard_prepare',
            [
                'btn_mode' => 'Install',
                'install_file' => $id,
                'mode' => 'Install',
            ]
        );
        $this->submitForm('index.php?module=Administration&view=module&action=UpgradeWizard_commit',
            [
                'mode' => 'PreCheck',
                'package_id' => $id,
            ]
        );
        $this->submitForm('index.php?module=Administration&view=module&action=UpgradeWizard_commit',
            [
                'mode' => 'Scan',
                'package_id' => $id,
            ]
        );
        $this->submitForm('index.php?module=Administration&view=module&action=UpgradeWizard_commit',
            [
                'mode' => 'RectorScan',
                'package_id' => $id,
            ]
        );
        $this->submitForm('index.php?module=Administration&view=module&action=UpgradeWizard_commit',
            [
                'mode' => 'Install',
                'package_id' => $id,
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
    public function installMLP(MLPBuilder $mlp): self
    {
        $path = $mlp->getPath();
        $name = $mlp->getName();

        $this->uploadMLP($path)
            ->extractRegexp('package_id', "~var mti_data\s*=\s*\[\[\".*$name\",\"(.*?)\"~is")
            ->extractCsrfToken();
        return $this->installUploadedPackage($this->getVar('package_id'));
    }

    public function bwcLogin(): self
    {
        $sidRequest = new Request(
            'POST',
            $this->prependBase('/oauth2/bwc/login'),
            ['Content-Type' => 'application/json'],
            json_encode([])
        );
        $this->send($sidRequest);
        $status = $this->lastResponse->getStatusCode();
        if (!preg_match("/PHPSESSID=([^;]+);/", $this->lastResponse->getHeaderLine('Set-Cookie'), $m)) {
            // No new cookie on a successful call: this client already holds a BWC session (second bwcLogin()).
            if ($status === 200) {
                return $this;
            }
            throw new \RuntimeException("Session ID not found (bwc/login returned HTTP $status)");
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
            preg_match('~SUGAR\.csrf\.form_token\s*=\s*"(.*?)"~is', $content, $m);
            if (!empty($m[1])) {
                return $m[1];
            }
            preg_match('~name="token"\svalue\s*=\s*"(.*?)"~is', $content, $m);
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
        string  $action,
        array   $data,
        ?string $formUri = null,
        string  $method = 'POST',
        array   $headers = [],
                $options = []
    ): self
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
        array  $data = [],
        array  $headers = [],
        array  $options = []
    ): self
    {
        $method = strtoupper($method);
        $path = parse_url($endpoint, PHP_URL_PATH) ?? $endpoint;
        if ($method === 'PUT' && rtrim($path, '/') === '/me/preferences') {
            $this->snapshotPreferences();
        }

        $request = new Request(
            $method,
            $this->prependBase($endpoint),
            [
                'Content-Type' => 'application/json',
            ] + $headers,
            json_encode($data)
        );
        $this->send($request, $options);

        if ($method === 'POST') {
            $this->trackCreatedRecords($path);
        }
        return $this;
    }

    /**
     * IdM (cloud identity / STS) mode: an unauthenticated /ping answers 401 with the STS login url.
     * Same check login() uses to switch to idmLogin().
     */
    public function isIdmMode(): bool
    {
        if (static::$idmMode === null) {
            $request = new Request('GET', $this->prependBase('/ping?platform=' . rawurlencode($this->config->getPlatform())));
            $response = $this->getClient()->send($request); // bypasses session and hooks
            static::$idmMode = $response->getStatusCode() === 401
                && $this->isIdmPong(json_decode((string)$response->getBody(), true));
        }
        return static::$idmMode;
    }

    private function isIdmPong(mixed $pongData): bool
    {
        return is_array($pongData) && isset($pongData['url']) && str_contains((string)$pongData['url'], 'https://sts');
    }

    /**
     * Browser session for Sugar: BWC cookies (from the HTTP client) plus the current OAuth token in
     * Sidecar's storage (prod:SugarCRM:AuthAccessToken), so both BWC and Sidecar pages render logged in.
     */
    protected function browserState(string $url): array
    {
        $state = parent::browserState($url);
        if (isset($this->session) && $this->session instanceof SugarSession) {
            $this->completeUserSetup();
            $prefix = (string)$this->config->get('sidecarStoragePrefix', 'prod:SugarCRM:');
            $state['localStorage'] = [$prefix . 'AuthAccessToken' => json_encode($this->session->getAccessToken())];
            // Sidecar refreshes the token when metadata changed (412) and logs out without a refresh token.
            if ($this->session->getRefreshToken() !== null) {
                $state['localStorage'][$prefix . 'AuthRefreshToken'] = json_encode($this->session->getRefreshToken());
            }
            $state['returnStorage'] = [$prefix . 'AuthAccessToken', $prefix . 'AuthRefreshToken'];
        }
        return $state;
    }

    /** @var array<string, true> access tokens whose user already has the setup wizard completed */
    private array $setupCompleted = [];

    /**
     * Sidecar shows the first-login "User Profile Setup" wizard instead of the requested page until the
     * user's `ut` preference is set (fresh instances, users from createUser()). Sent directly, so it isn't
     * snapshotted/restored like the scenario's own preference changes.
     */
    private function completeUserSetup(): void
    {
        $token = $this->session->getAccessToken();
        if (isset($this->setupCompleted[$token])) {
            return;
        }
        $request = new Request('PUT', $this->prependBase('/me/preferences'), ['Content-Type' => 'application/json'], json_encode(['ut' => 1]));
        $this->getClient()->send($this->session->init($request));
        $this->setupCompleted[$token] = true;
    }

    /**
     * Sidecar refreshes tokens (e.g. after a metadata change), which revokes the ones this scenario holds:
     * adopt the browser's tokens so subsequent API calls and browser visits keep working.
     */
    protected function afterBrowse(array $result): void
    {
        $prefix = (string)$this->config->get('sidecarStoragePrefix', 'prod:SugarCRM:');
        $access = json_decode((string)($result['storage'][$prefix . 'AuthAccessToken'] ?? 'null'), true);
        $refresh = json_decode((string)($result['storage'][$prefix . 'AuthRefreshToken'] ?? 'null'), true);
        if (is_string($access) && $access !== '' && isset($this->session) && $this->session instanceof SugarSession
            && $access !== $this->session->getAccessToken()) {
            $wasCurrent = $this->session === $this->currentUserSession;
            $this->session = new SugarSession($access, is_string($refresh) ? $refresh : null);
            if ($wasCurrent) {
                $this->currentUserSession = $this->session;
            }
        }
    }

    /**
     * Run cleanup requests as the given user (admin by default); re-logs in only when needed.
     */
    public function cleanupAs(string $username = 'admin'): self
    {
        if (!$this->isLoggedInAs($username)) {
            // Fresh client (empty cookie jar): the scenario may have left portal/BWC cookies behind.
            $this->client = $this->initClient();
            $this->session = null;
            $this->loginAs($username);
        }
        return $this;
    }

    protected function isLoggedInAs(string $username): bool
    {
        return $this->currentUser !== null
            && $this->currentUser[0] === $username
            && isset($this->session)
            && $this->session === $this->currentUserSession;
    }

    /**
     * Records created via REST (POST /<Module> or POST /<Module>/<id>/link/<rel>) are deleted after the scenario.
     */
    protected function trackCreatedRecords(string $path): void
    {
        if ($this->getLastResponse()->getStatusCode() !== 200) {
            return;
        }
        $isCreate = (bool)preg_match('~^/[A-Z][A-Za-z0-9_]*/?$~', $path);
        $isLink = (bool)preg_match('~^/[A-Z][A-Za-z0-9_]*/[^/]+/link/[^/]+/?$~', $path);
        if (!$isCreate && !$isLink) {
            return;
        }
        $stream = $this->getLastResponse()->getBody();
        $body = json_decode((string)$stream, true);
        // Leave the stream as we found it: scenarios often read it with getContents().
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        if (!is_array($body)) {
            return;
        }
        $record = $isCreate ? $body : ($body['related_record'] ?? null);
        if (empty($record['id']) || empty($record['_module'])) {
            return;
        }
        $this->deferDelete($record['_module'], $record['id']);
    }

    public function deferDelete(string $module, string $id): self
    {
        return $this->defer(function () use ($module, $id) {
            // Users are IdM-managed entities: deleting them via REST requires the skip flag.
            $suffix = $module === 'Users' ? '?skip_idm_mode_restrictions=1' : '';
            $this->cleanupAs('admin')->apiCall("/$module/$id$suffix", 'DELETE');
            $status = $this->getLastResponse()->getStatusCode();
            if ($status === 200 || $status === 404) {
                return;
            }
            // Legacy (BWC) modules may not support REST deletion: fall back to the classic UI action.
            $this->bwcLogin();
            $this->submitForm(
                'index.php',
                ['module' => $module, 'action' => 'Delete', 'record' => $id],
                "index.php?module=$module&action=DetailView&record=" . rawurlencode($id),
            );
            $bwcStatus = $this->getLastResponse()->getStatusCode();
            $this->apiCall("/$module/$id");
            if ($this->getLastResponse()->getStatusCode() !== 404) {
                throw new \RuntimeException(
                    "record still exists: REST DELETE returned $status, BWC Delete returned $bwcStatus"
                );
            }
        }, "delete $module/$id");
    }

    /**
     * Saves the current user's preferences before the first PUT /me/preferences and restores them afterwards.
     */
    protected function snapshotPreferences(): void
    {
        if ($this->currentUser === null) {
            return;
        }
        [$username, $password] = $this->currentUser;
        if (isset($this->preferencesSnapshotted[$username])) {
            return;
        }
        $this->preferencesSnapshotted[$username] = true;
        $this->send(new Request('GET', $this->prependBase('/me/preferences')));
        $snapshot = json_decode((string)$this->getLastResponse()->getBody(), true);
        if ($this->getLastResponse()->getStatusCode() !== 200 || !is_array($snapshot)) {
            return;
        }
        $this->defer(function () use ($username, $password, $snapshot) {
            if (!$this->isLoggedInAs($username)) {
                $this->client = $this->initClient();
                $this->session = null;
                $this->login($username, $password);
            }
            $this->apiCall('/me/preferences', 'PUT', $snapshot);
            if ($this->getLastResponse()->getStatusCode() !== 200) {
                throw new \RuntimeException('restore returned ' . $this->getLastResponse()->getStatusCode());
            }
        }, "restore preferences of $username");
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
            && (is_null($this->maxVersion) || self::$sugarVersion['sugar_version'] <= $this->maxVersion)
            && $this->hasRequiredLicense();
    }

    /**
     * @return string[] license types available on the instance, e.g. SUGAR_SELL, SUGAR_SERVE, CURRENT
     */
    public function getInstanceLicenses(): array
    {
        return static::$instanceLicenses ?? [];
    }

    public function hasLicense(string ...$licenses): bool
    {
        return (bool)array_intersect($licenses, $this->getInstanceLicenses());
    }

    private function hasRequiredLicense(): bool
    {
        foreach ((new \ReflectionClass($this))->getAttributes(\Regression\Attribute\RequiresLicense::class) as $attribute) {
            if (!$this->hasLicense(...$attribute->newInstance()->licenses)) {
                return false;
            }
        }
        return true;
    }

    protected function detectInstanceLicenses(): void
    {
        $this->asAdmin(function () {
            $this->apiCall('/me');
            static::$instanceLicenses = (array)($this->json()['current_user']['licenses'] ?? []);
        });
    }

    public function createRole(Role $role): self
    {
        $this
            ->submitForm('index.php?module=ACLRoles&action=EditView', [], 'index.php?module=ACLRoles&action=EditView')
            ->submitForm(
                'index.php',
                [
                    'module' => 'ACLRoles',
                    'action' => 'Save',
                    'name' => $role->getName(),
                ]
            )
            ->extractRegexp('roleId', '/<input type="hidden" name="record" value="(.*?)"/');
        $role->setId($this->getVar('roleId'));
        if (!empty($role->getId())) {
            $this->deferDelete('ACLRoles', $role->getId());
        }
        $guids = [];
        foreach ($role->getPermissions() as $modulePermissions) {
            $guids = array_merge($guids, $this->prepareACLFormData($modulePermissions));
        }
        if (!empty($guids)) {
            $this->submitForm(
                'index.php',
                [
                    'record' => $role->getId(),
                    'module' => 'ACLRoles',
                    'action' => 'Save',
                    ...$guids
                ]
            );
        }
        return $this;
    }

    protected function prepareACLFormData(ModulePermissions $modulePermissions): array
    {
        $module = $modulePermissions->getModule()->value;
        $guids = [];
        foreach ($modulePermissions->getPermissions() as $permission) {
            $id = sprintf('ACLEditView_Access_%s_%s', $module, $permission->getName());
            $regExp = sprintf('/id="%s">\s*<div\s+style="display: none" id="(.*)"/', $id);
            $this->extractRegexp($id, $regExp);
            $guids['act_guid' . $this->getVar($id)] = $permission->value;
        }
        return $guids;
    }

    protected function detectSugarSubscription(): void
    {
        if ($this->config->getLicense() === Config::DEFAULT_LICENSE) {
            $this
                ->loginAs('admin')
                ->bwcLogin()
                ->get('index.php?module=Administration&action=LicenseSettings&bwcFrame=1')
                ->extractRegexp('licenseKey', '/name=\'license_key\'.*?value="(.*?)"/')
                ->logout();

            $this->config->set('license', $this->getVar('licenseKey'));
        }

        $subscriptionRequest = new Request('GET', 'https://authenticate.sugarcrm.com/rest/subscription/' . $this->config->getLicense());

        $this
            ->send($subscriptionRequest, [
                'chromeClientOptions' => new ChromeClientOptions(skipInterception: true),
            ])
            ->extract('subscriptionProduct', function (ResponseInterface $response) {
                $data = json_decode((string)$response->getBody(), true);

                return $data['subscription']['product'] ?? false;
            });

        static::$subscriptionProduct = $this->getVar('subscriptionProduct');
    }

    /**
     * Custom API platforms registered on the instance (Admin -> API Platforms).
     * @return string[]
     */
    public function getCustomApiPlatforms(): array
    {
        return $this->onBasePlatform(
            fn() => array_keys(array_filter($this->loadApiPlatforms(), fn(array $p) => !empty($p['custom'])))
        );
    }

    /**
     * Platform management always runs as admin on 'base' with a separate client: the worker's own
     * platform may be the one being removed.
     */
    private function onBasePlatform(callable $callback): mixed
    {
        $platform = $this->config->getPlatform();
        $this->config->set('platform', 'base');
        try {
            return $this->asAdmin($callback);
        } finally {
            $this->config->set('platform', $platform);
        }
    }

    /**
     * Replace the list of custom API platforms, keeping notification options of all platforms.
     * @param string[] $custom
     */
    public function saveCustomApiPlatforms(array $custom): self
    {
        $this->onBasePlatform(fn() => $this->doSaveCustomApiPlatforms($custom));
        return $this;
    }

    private function doSaveCustomApiPlatforms(array $custom): void
    {
        $platforms = $this->loadApiPlatforms();
        $options = [];
        foreach ($platforms as $name => $p) {
            if (empty($p['custom']) || in_array($name, $custom, true)) {
                $options[$name] = ['enable_notifications' => $p['enable_notifications'] ?? true];
            }
        }
        foreach ($custom as $name) {
            $options[$name] ??= ['enable_notifications' => false];
        }
        $this->submitForm(
            'index.php',
            [
                'module' => 'Administration',
                'action' => 'saveApiPlatforms',
                'custom_api_platforms' => json_encode(array_values($custom)),
                'platformoptions' => json_encode($options),
                'to_pdf' => '1',
            ],
        );
        if ($this->getLastResponse()->getStatusCode() !== 200) {
            throw new \RuntimeException('Saving API platforms failed: HTTP ' . $this->getLastResponse()->getStatusCode());
        }
    }

    /**
     * @return array<string, array{custom: bool, enable_notifications: bool}>
     */
    protected function loadApiPlatforms(): array
    {
        $url = 'index.php?module=Administration&action=apiplatforms';
        $this->bwcLogin();
        $this->send(new Request('GET', $url))->extractCsrfToken();
        if (!preg_match('/var api_platforms = (\[.*?\]);/s', (string)$this->getLastResponse()->getBody(), $m)) {
            throw new \RuntimeException('Cannot read API platforms from ' . $url);
        }
        $result = [];
        foreach (json_decode($m[1], true) ?: [] as $p) {
            $result[$p['name']] = [
                'custom' => (bool)($p['custom'] ?? false),
                'enable_notifications' => (bool)($p['enable_notifications'] ?? true),
            ];
        }
        return $result;
    }
}
