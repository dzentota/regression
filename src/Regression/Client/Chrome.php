<?php

namespace Regression\Client;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\UriResolver;

class Chrome implements ClientInterface
{
    private BrowserFactory $browserFactory;
    private array $options;
    private string $baseUri;
    private ProcessAwareBrowser $browser;
    private Page $currentPage;

    public function __construct(BrowserFactory $browserFactory, array $options, string $baseUri)
    {
        $this->browserFactory = $browserFactory;
        $this->options = $options;
        $this->baseUri = $baseUri;
        $this->browser = $browserFactory->createBrowser($options);
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
        if (!empty($options)) {
            $browser = $this->browserFactory->createBrowser(
                array_replace_recursive($this->options, $options)
            );
        } else {
            $browser = $this->browser;
        }
        $this->currentPage = $browser->createPage();

        $statusCode = 500;

        $responseHeaders = [];

        $this->currentPage->getSession()->once(
            "method:Network.responseReceived",
            function ($params) use (& $statusCode, & $responseHeaders) {
                $statusCode = $params['response']['status'];
                $responseHeaders = $this->sanitizeResponseHeaders($params['response']['headers']);
            }
        );

        $content = '';
        $this->currentPage->getSession()->once(
            'method:Network.loadingFinished',
            function (array $params) use ( &$content): void {
                $request_id = $params["requestId"] ?? null;
                $data = $this->currentPage->getSession()->sendMessageSync(
                    new Message('Network.getResponseBody',
                        ['requestId' => $request_id])
                )->getData();
                $content = $data["result"]["body"] ?? '';
            });
        $uri = UriResolver::resolve(Utils::uriFor($this->baseUri), $request->getUri());

        // Assume that ANY alert dialog is an XSS
//        $page->getSession()->on('method:Page.javascriptDialogOpening', function (array $params) use ($page): void {
//            echo "Dialog opened: " . $params["message"] . PHP_EOL;
//            $page->getSession()->sendMessageSync(new Message('Page.handleJavaScriptDialog', ['accept' => true]));
//        });
        $this->currentPage->addPreScript(
            <<<'JS'
            window.alert=function(){
                document.documentElement.innerHTML = 'XSS!!! Detected';
            }
            JS
        );
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(", ", $values);
        }
        if (strtoupper($request->getMethod()) !== 'GET') {
            $jsHeaders = json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $body = json_encode((string)$request->getBody(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ajaxJsCode = <<<JS
                var postData = $body;
                async function performAjaxRequest() {
                    try {
                      const response = await fetch('{$uri->__toString()}', {
                        method: 'POST',
                        headers: $jsHeaders,
                        body: postData
                      });
                
                      if (response.ok) {
                        return await response.text();
                      } else {
                        throw new Error('Network response was not ok.');
                      }
                    } catch (error) {
                      throw new Error(error.message);
                    }
                }
                // Execute the function and return the response
                performAjaxRequest();
            JS;
            // Evaluate the JavaScript code in the browser context
            $content = $this->currentPage
                ->evaluate($ajaxJsCode)
                ->waitForResponse()
                ->getReturnValue();
            return new Response($statusCode, $responseHeaders, $content);
        } else {
            if (!empty($headers)) {
                $this->currentPage->setExtraHTTPHeaders($headers);
            }
            $this->currentPage
                ->navigate($uri)
                ->waitForNavigation();
            return new Response($statusCode, $responseHeaders, $this->isHtmlPage($responseHeaders) ?
                $this->currentPage->getHtml() : $content);
        }


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

    public function __call(string $method, array $params = [])
    {
        return $this->browserFactory->$method(...$params);
    }

    public function getCurrentPage(): Page
    {
        return $this->currentPage;
    }
}