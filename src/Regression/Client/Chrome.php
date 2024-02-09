<?php

namespace Regression\Client;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\UriResolver;

class Chrome implements ClientInterface, SessionInterface
{
    private BrowserFactory $browserFactory;
    private Browser $browser;
    private array $options;
    private string $baseUri;

    public function __construct(BrowserFactory $browserFactory, array $options, string $baseUri)
    {
        $this->browserFactory = $browserFactory;
        $this->options = $options;
        $this->baseUri = $baseUri;
    }

    private function initBrowser(array $options): void
    {
        $mergedOptions = array_replace_recursive($this->options, $options);
        $mergedOptions['keepAlive'] = true;

        try {
            $socket = @file_get_contents('/tmp/chrome-php-demo-socket');

            $this->browser = $this->browserFactory::connectToBrowser($socket, $mergedOptions);
        } catch (\Throwable $e) {
            $this->browser = $this->browserFactory->createBrowser($mergedOptions);

            file_put_contents('/tmp/chrome-php-demo-socket', $this->browser->getSocketUri(), LOCK_EX);
        }
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
        $this->initBrowser($options);
        $page = $this->browser->createPage();

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
                $modifiedParams['postData'] = base64_encode($request->getBody()->getContents());
            }

            $page->getSession()->sendMessageSync(new Message('Fetch.continueRequest', $modifiedParams));
        });

        $page->getSession()->sendMessage(new Message('Fetch.enable', ['patterns' => [['urlPattern' => '*']]]));

//        $page->getSession()->once(
//            "method:Network.responseReceived",
//            function ($params) use (& $statusCode, & $responseHeaders) {
//                $statusCode = $params['response']['status'];
//                $responseHeaders = $this->sanitizeResponseHeaders($params['response']['headers']);
//            }
//        );

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
            ->waitForNavigation(Page::LOAD, 300000);
        return new Response($statusCode, $responseHeaders, $this->isHtmlPage($responseHeaders) ?
            $page->getHtml() : $content);
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
        $options = $this->browserFactory->getOptions();
        return $option === null ? $options : $options[$option];
    }

    public function closeSession(): void
    {
        $this->browser->close();
    }
}