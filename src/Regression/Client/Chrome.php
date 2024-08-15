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

        $page->getSession()->on('method:Fetch.requestPaused', function (array $params) use ($page, $request): void {
            $method = $request->getMethod();
            $modifiedParams = [
                'requestId' => $params['requestId'],
                'method' => $method,
            ];

            foreach (array_merge_recursive($params['request']['headers'], $request->getHeaders()) as $name => $value) {
                $modifiedParams['headers'][] = [
                    'name' => $name,
                    'value' => is_string($value)
                        ? $value
                        : implode(', ', $value),
                ];
            }

            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $modifiedParams['postData'] = base64_encode((string)$request->getBody());
            }

            $page->getSession()->sendMessageSync(new Message('Fetch.continueRequest', $modifiedParams));
        });

        if (!$chromeClientOptions->skipInterception) {
            $page->getSession()->sendMessage(new Message('Fetch.enable', ['patterns' => [['urlPattern' => '*']]]));
        }

        $page->getSession()->once(
            'method:Network.responseReceivedExtraInfo',
            function ($params) use (& $statusCode, & $responseHeaders) {
                $statusCode = $params['statusCode'];
                $responseHeaders = $this->sanitizeResponseHeaders($params['headers']);
            }
        );

        $content = '';
        $page->getSession()->once(
            'method:Network.loadingFinished',
            function (array $params) use ($page, &$content): void {
                $request_id = $params["requestId"] ?? null;
                $data = $page->getSession()->sendMessageSync(
                    new Message('Network.getResponseBody',
                        ['requestId' => $request_id])
                )->getData();
                $content = $data["result"]["body"] ?? '';
            });
        $uri = UriResolver::resolve(Utils::uriFor($this->baseUri), $request->getUri());

        // Assume that ANY alert dialog is an XSS
        $page->addPreScript(
            <<<'JS'
            window.alert=function(){
                document.documentElement.innerHTML = 'XSS!!! Detected';
            }
            JS
        );

        $page->navigate($uri)
            ->waitForNavigation(
                $chromeClientOptions->pageLoadingStep,
                $chromeClientOptions->pageLoadingTimeout,
            );
        $response = new Response($statusCode, $responseHeaders, $this->isHtmlPage($responseHeaders) ?
            $page->getHtml() : $content);

        if (!$chromeClientOptions->leaveOpened) {
            $page->close();
        }

        return $response;
    }

    private function isHtmlPage(array $headers): bool
    {
        return (isset($headers['Content-Type']) && $headers['Content-Type'] === 'text/html');
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