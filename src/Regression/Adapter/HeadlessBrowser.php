<?php
declare(strict_types=1);

namespace Regression\Adapter;

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use Regression\Client\Chrome;
use Regression\Client\ClientInterface;

trait HeadlessBrowser
{
    private Browser $browser;

    private Page $mainPage;

    private function initBrowser(): array
    {
        $options = [
            'headless' => true,
            'windowSize' => [1920, 1080],
            'enableImages' => false,
            'ignoreCertificateErrors' => true,
            'keepAlive' => true,
        ];
        $browserFactory = new BrowserFactory();

        // Connect to a remote CDP endpoint (e.g. browserless.io) when configured.
        // Set 'browserlessUrl' in Config, e.g. 'ws://browserless:3000' or
        // 'wss://chrome.browserless.io?token=MY_TOKEN'.
        $browserlessUrl = $this->config->get('browserlessUrl');
        if ($browserlessUrl) {
            $this->browser = $browserFactory::connectToBrowser(
                $this->resolveWebSocketUri($browserlessUrl),
                $options
            );
            return $options;
        }

        // Local Chrome fallback: reuse an existing instance via socket file or spawn a new one.
        $socketFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chrome-php-regression-socket';

        if (!(is_file($socketFilePath) && is_readable($socketFilePath))) {
            touch($socketFilePath);
        }

        try {
            $socket = file_get_contents($socketFilePath);

            $this->browser = $browserFactory::connectToBrowser($socket, $options);
        } catch (\Throwable $e) {
            $this->browser = $browserFactory->createBrowser($options);

            file_put_contents($socketFilePath, $this->browser->getSocketUri(), LOCK_EX);
        }

        return $options;
    }

    /**
     * Resolves the full WebSocket debugger URI required by chrome-php.
     *
     * A bare URL such as ws://browserless:3000 has no path, which the underlying
     * WebSocket library rejects.  We fetch /json/version from the CDP endpoint to
     * get the real debugger URI, then replace its host/port with the ones from the
     * configured URL so Docker service names keep working correctly.
     */
    private function resolveWebSocketUri(string $browserlessUrl): string
    {
        $parsed = parse_url($browserlessUrl);
        $wsScheme = $parsed['scheme'] ?? 'ws';
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ('wss' === $wsScheme ? 443 : 80);
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        // If a path beyond the root is already present the caller provided a full URI.
        if (!empty($parsed['path']) && $parsed['path'] !== '/') {
            return $browserlessUrl;
        }

        $httpScheme = str_replace('ws', 'http', $wsScheme);
        $versionUrl = "{$httpScheme}://{$host}:{$port}/json/version{$query}";

        $json = @file_get_contents($versionUrl);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (isset($data['webSocketDebuggerUrl'])) {
                // Keep the path from the returned URL but use the configured host/port
                // because the returned URL may contain 127.0.0.1 instead of the
                // Docker service name.
                // Browserless.io v1 returns ws://host:port with no path; fall back to '/'
                // so the WebSocket library accepts the URI.
                $returnedPath = parse_url($data['webSocketDebuggerUrl'], PHP_URL_PATH) ?: '/';
                return "{$wsScheme}://{$host}:{$port}{$returnedPath}";
            }
        }

        return $browserlessUrl;
    }

    protected function initClient(): ClientInterface
    {
        $options = $this->initBrowser();

        return new Chrome($this->browser, $options, $this->config->getBaseUri());
    }

    public function __destruct()
    {
        $this->browser->close();
    }

    protected function getLastOpenedPage(): Page
    {
        $pageId = Chrome::getLastOpenedPage();

        if (!$pageId) {
            throw new \Exception('No opened pages was found in Chrome. If you want to leave page as opened then add flag "leaveOpened" => true to options.');
        }

        return $this->browser->getPage($pageId);
    }
}