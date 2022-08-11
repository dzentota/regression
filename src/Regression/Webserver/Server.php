<?php

declare(strict_types=1);

namespace Regression\Webserver;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class Server
{
    /**
     * The current host
     *
     * @var string
     */
    protected string $host;

    /**
     * The current port
     *
     * @var int
     */
    protected int $port;

    /**
     * The binded socket
     *
     * @var resource
     */
    protected $socket = null;

    private $client;

    /**
     * Construct new Server instance
     *
     * @param string $host
     * @param int $port
     * @return void
     * @throws \Exception
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
        $this->createSocket();
        $this->bind();
    }

    /**
     *  Create new socket resource
     */
    protected function createSocket(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
    }

    /**
     * Bind the socket resource
     *
     * @return void
     * @throws \Exception
     */
    protected function bind()
    {
        if (!socket_bind($this->socket, $this->host, $this->port)) {
            throw new \Exception('Could not bind: ' . $this->host . ':' . $this->port . ' - ' . socket_strerror(socket_last_error()));
        }
    }

    /**
     * Listen for requests
     *
     * @param callable $callback
     * @return void
     * @throws \Exception
     */
    public function listen(callable $callback): void
    {
        while (true) {
            // listen for connections
            socket_listen($this->socket);

            // try to get the client socket resource
            // if false we got an error close the connection and continue
            if (!$this->client = socket_accept($this->socket)) {
                socket_close($this->client);
                continue;
            }

            // 1024 should be enough for GET and small POST / PUT requests
            $request = Message::parseRequest(socket_read($this->client, 1024));

            // execute the callback
            $response = call_user_func($callback, $request);

            if (!$response instanceof ResponseInterface) {
                $response = new Response(404);
            }

            $this->emit($response);
            // close the connection so we can accept new ones
            socket_close($this->client);
        }
    }

    protected function emit(ResponseInterface $response)
    {
        $statusLine = $this->emitStatusLine($response);
        $headers = $this->emitHeaders($response);
        $body = (string) $response->getBody();
        $httpResponse = <<<HTTP
$statusLine
$headers

$body
HTTP;
        // write the response to the client socket
        socket_write($this->client, $httpResponse, strlen($httpResponse));

    }

    private function emitStatusLine(ResponseInterface $response): string
    {
        $reasonPhrase = $response->getReasonPhrase();
        $statusCode   = $response->getStatusCode();

        return sprintf(
            'HTTP/%s %d%s',
            $response->getProtocolVersion(),
            $statusCode,
            $reasonPhrase ? ' ' . $reasonPhrase : ''
        );
    }

    private function emitHeaders(ResponseInterface $response): string
    {
        $headers = [];
        foreach ($response->getHeaders() as $header => $values) {
            assert(is_string($header));
            $name  = $this->filterHeader($header);
            foreach ($values as $value) {
                $headers[] = sprintf(
                    '%s: %s',
                    $name,
                    $value
                );
            }
        }
        return implode("\n", $headers);
    }

    /**
     * Filter a header name to wordcase
     */
    private function filterHeader(string $header): string
    {
        return ucwords($header, '-');
    }
}
