<?php
declare(strict_types=1);

namespace Regression\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Regression\RegressionException;
use Regression\Scenario;
use Regression\Session;

final class ScenarioTest extends TestCase
{
    public function testSend(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response();
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);

        $callback = $this->createMock(Callback::class);
        $callback->expects($this->once())
            ->method('beforeRequest')
            ->with($request);
        $callback->expects($this->once())
            ->method('afterResponse')
            ->with($response);

        $scenario->onRequest([$callback, 'beforeRequest']);
        $scenario->onResponse([$callback, 'afterResponse']);
        $this->setProtectedProperty($scenario, 'session', new NullSession());

        $scenario->send($request);
    }

    public function testExpectSubstring(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $scenario->expectSubstring($responseText);
    }

    public function testExpectSubstringFail(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $scenario->expectSubstring('non-existed text');
    }

    public function testExpectSubstringFailWithCustomMessage(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $exceptionMessage = 'where is my text?';
        $this->expectExceptionMessage($exceptionMessage);
        $scenario->expectSubstring('non-existed text', $exceptionMessage);
    }

    public function testExpectRegexp(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $scenario->expectRegexp('/Text$/is');
    }

    public function testExpectRegexpFail(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $scenario->expectRegexp('/message/is');
    }

    public function testExpectRegexpFailWithCustomMessage(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $exceptionMessage = 'Regular expression rocks';
        $this->expectExceptionMessage($exceptionMessage);
        $scenario->expectRegexp('/foobar/', $exceptionMessage);
    }

    public function testExpect(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $scenario->expect(function (Response $response) use ($responseText) {
            return (string)$response->getBody() === $responseText;
        });
    }

    public function testExpectFail(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $scenario->expect(function (Response $response) use ($responseText) {
            return (string)$response->getBody() === 'some text';
        });
    }

    public function testExpectFailWithCustomMessage(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'response text';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $exceptionMessage = 'My callback deserves more!';
        $this->expectExceptionMessage($exceptionMessage);
        $scenario->expect(function (Response $response) use ($responseText) {
            return (string)$response->getBody() === 'some text';
        }, $exceptionMessage);
    }

    public function testExtractRegexp(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'hello world';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $scenario->extractRegexp('hello', '/^([a-z]+)\s+([a-z]+)/s');
        $scenario->extractRegexp('world', '/^([a-z]+)\s+([a-z]+)/s', 2);
        $this->assertEquals('hello', $scenario->getVar('hello'));
        $this->assertEquals('world', $scenario->getVar('world'));
    }

    public function testExtractRegexpFail(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'hello world';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $scenario->extractRegexp('foo', '/foobar/s');
    }

    public function testExtractRegexpFailWithCustomMessage(): void
    {
        $request = new Request('GET', 'https://example.com');
        $responseText = 'hello world';
        $response = new Response(200, [], $responseText);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $exceptionMessage = "Oops. Regexp doesn't match";
        $this->expectExceptionMessage($exceptionMessage);
        $scenario->extractRegexp('foo', '/foobar/s', 1, $exceptionMessage);
    }

    public function testExpectStatusCode(): void
    {
        $request = new Request('GET', 'https://example.com');
        $status = 500;
        $response = new Response($status, [], '');
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $scenario->expectStatusCode($status);
    }

    public function testExpectStatusCodeFail(): void
    {
        $request = new Request('GET', 'https://example.com');
        $status = 500;
        $response = new Response($status, [], '');
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $scenario->expectStatusCode(200);
    }

    public function testExpectStatusCodeFailWithCustomMessage(): void
    {
        $request = new Request('GET', 'https://example.com');
        $status = 500;
        $response = new Response($status, [], '');
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));

        $scenario = $this->getMockForAbstractClass(Scenario::class, [$client]);
        $scenario->send($request);
        $this->expectException(RegressionException::class);
        $scenario->expectStatusCode(200);
    }

    /**
     * Sets a protected property on a given object via reflection
     *
     * @param $object - instance in which protected value is being modified
     * @param $property - property on instance being modified
     * @param $value - new value of the property being modified
     *
     * @return void
     * @throws \ReflectionException
     */
    private function setProtectedProperty($object, $property, $value)
    {
        $reflection = new \ReflectionClass($object);
        $reflection_property = $reflection->getProperty($property);
        $reflection_property->setAccessible(true);
        $reflection_property->setValue($object, $value);
    }

}

class Callback
{
    public function beforeRequest(Request $request)
    {

    }

    public function afterResponse(Response $response)
    {

    }

}

final class NullSession implements Session
{
    public function init(RequestInterface $request): RequestInterface
    {
        return $request;
    }
}