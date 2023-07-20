<?php

namespace Regression\Client;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\UriResolver;

class Chrome implements ClientInterface
{
    private BrowserFactory $browserFactory;
    private array $options;
    private string $baseUri;

    public function __construct(BrowserFactory $browserFactory, array $options, string $baseUri)
    {
        $this->browserFactory = $browserFactory;
        $this->options = $options;
        $this->baseUri = $baseUri;
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
        //@todo add cache based on $options
        /**
         * use \HeadlessChromium\Exception\BrowserConnectionFailed;
         *
         * // path to the file to store websocket's uri
         * $socket = \file_get_contents('/tmp/chrome-php-demo-socket');
         *
         * try {
         * $browser = BrowserFactory::connectToBrowser($socket);
         * } catch (BrowserConnectionFailed $e) {
         * // The browser was probably closed, start it again
         * $factory = new BrowserFactory();
         * $browser = $factory->createBrowser([
         * 'keepAlive' => true,
         * ]);
         *
         * // save the uri to be able to connect again to browser
         * \file_put_contents($socketFile, $browser->getSocketUri(), LOCK_EX);
         * }
         */
        $browser = $this->browserFactory->createBrowser(
            array_replace_recursive($this->options, $options)
        );
        $page = $browser->createPage();

        $statusCode = 500;

        $responseHeaders = [];

        $page->getSession()->once(
            "method:Network.responseReceived",
            function ($params) use (& $statusCode, & $responseHeaders) {
                $statusCode = $params['response']['status'];
                $responseHeaders = $this->sanitizeResponseHeaders($params['response']['headers']);
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
            ->waitForNavigation();
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
}