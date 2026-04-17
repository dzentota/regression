<?php

namespace Regression\Client;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use HeadlessChromium\Browser;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\UriResolver;
use Regression\Helpers\Client\ChromeClientOptions;

class Chrome implements ClientInterface
{
    protected Browser $browser;
    private array $options;
    private string $baseUri;

    /** @var string[] */
    private static array $openedPageIds;

    public function __construct(Browser $browser, array $options, string $baseUri)
    {
        $this->browser = $browser;
        $this->options = $options;
        $this->baseUri = $baseUri;
        self::$openedPageIds = [];
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return ResponseInterface
     * @throws \HeadlessChromium\Exception\CommunicationException
     * @throws \HeadlessChromium\Exception\CommunicationException\CannotReadResponse
     * @throws \HeadlessChromium\Exception\CommunicationException\InvalidResponse
     * @throws \HeadlessChromium\Exception\CommunicationException\ResponseHasError
     * @throws \HeadlessChromium\Exception\NavigationExpired
     * @throws \HeadlessChromium\Exception\NoResponseAvailable
     * @throws \HeadlessChromium\Exception\OperationTimedOut
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $chromeClientOptions = $options['chromeClientOptions'] ?? new ChromeClientOptions();

        $this->options = array_replace_recursive($this->options, $options);
        $page = $this->browser->createPage();

        if ($chromeClientOptions->leaveOpened) {
            self::$openedPageIds[] = $page->getSession()->getTargetId();
        }

        $statusCode = 500;

        $responseHeaders = [];

        // Network-level requestId of the main document; used to filter Network events
        // so subrequest responses are never mistaken for the page response.
        $mainDocumentNetworkId = null;

        $page->getSession()->on('method:Fetch.requestPaused', function (array $params) use ($page, $request, &$mainDocumentNetworkId): void {
            $isMainDocument = ($params['resourceType'] ?? '') === 'Document';

            if ($isMainDocument) {
                // Record the network-level ID so we can match Network.* events below.
                $mainDocumentNetworkId = $params['networkId'] ?? null;

                // Apply the caller's method / headers only to the main navigation.
                $method = $request->getMethod();
                $modifiedParams = [
                    'requestId' => $params['requestId'],
                    'method' => $method,
                ];
                foreach (array_merge_recursive($params['request']['headers'], $request->getHeaders()) as $name => $value) {
                    $modifiedParams['headers'][] = [
                        'name' => $name,
                        'value' => is_string($value) ? $value : implode(', ', $value),
                    ];
                }
                if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    $modifiedParams['postData'] = base64_encode((string)$request->getBody());
                }
            } else {
                // Pass subrequests through unmodified so page-internal XHR/fetch
                // calls keep their original method and headers.
                $modifiedParams = ['requestId' => $params['requestId']];
            }

            $page->getSession()->sendMessageSync(new Message('Fetch.continueRequest', $modifiedParams));
        });

        if (!$chromeClientOptions->skipInterception) {
            $page->getSession()->sendMessage(new Message('Fetch.enable', ['patterns' => [['urlPattern' => '*']]]));
        }

        // Use on() (not once()) so we can filter by the main document's requestId.
        $page->getSession()->on(
            'method:Network.responseReceivedExtraInfo',
            function ($params) use (&$statusCode, &$responseHeaders, &$mainDocumentNetworkId) {
                if ($mainDocumentNetworkId !== null && ($params['requestId'] ?? null) === $mainDocumentNetworkId) {
                    $statusCode = $params['statusCode'];
                    $responseHeaders = $this->sanitizeResponseHeaders($params['headers']);
                }
            }
        );

        $content = '';
        $page->getSession()->on(
            'method:Network.loadingFinished',
            function (array $params) use ($page, &$content, &$mainDocumentNetworkId): void {
                if ($mainDocumentNetworkId !== null && ($params['requestId'] ?? null) === $mainDocumentNetworkId) {
                    $data = $page->getSession()->sendMessageSync(
                        new Message('Network.getResponseBody', ['requestId' => $params['requestId']])
                    )->getData();
                    $content = $data['result']['body'] ?? '';
                }
            });
        $uri = UriResolver::resolve(Utils::uriFor($this->baseUri), $request->getUri());

        // Allow self-signed / untrusted TLS certificates (e.g. mkcert in DDEV).
        // Security.enable must be called first so the domain is active.
        $page->getSession()->sendMessageSync(new Message('Security.enable'));
        $page->getSession()->sendMessageSync(
            new Message('Security.setIgnoreCertificateErrors', ['ignore' => true])
        );

        // Detect XSS via the CDP dialog event — fires for alert(), confirm(), and prompt().
        // The dialog is dismissed immediately so it never blocks navigation.
        $xssDetected = false;
        $page->getSession()->on(
            'method:Page.javascriptDialogOpening',
            function (array $params) use ($page, &$xssDetected): void {
                $xssDetected = true;
                $page->getSession()->sendMessageSync(
                    new Message('Page.handleJavaScriptDialog', ['accept' => false])
                );
            }
        );

        $page->navigate($uri)
            ->waitForNavigation(
                $chromeClientOptions->pageLoadingStep,
                $chromeClientOptions->pageLoadingTimeout,
            );

        $body = $this->isHtmlPage($responseHeaders) ? $page->getHtml() : $content;
        $response = new Response($statusCode, $responseHeaders, $xssDetected ? 'XSS!!! Detected' : $body);

        if (!$chromeClientOptions->leaveOpened) {
            $page->close();
        }

        return $response;
    }

    private function isHtmlPage(array $headers): bool
    {
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
        return str_starts_with($contentType, 'text/html');
    }

    /**
     * @param string[] $headers
     * @return string[]
     */
    protected function sanitizeResponseHeaders(array $headers): array
    {
        foreach ($headers as $key => $value) {
            $headers[$key] = explode(PHP_EOL, $value)[0];
        }

        return $headers;
    }

    public function getConfig($option = null)
    {
        return $option === null ? $this->options : $this->options[$option];
    }

    /**
     * @return false|string
     */
    public static function getLastOpenedPage()
    {
        $pages = self::$openedPageIds;

        return end($pages);
    }
}