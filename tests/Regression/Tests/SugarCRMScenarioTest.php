<?php
declare(strict_types=1);

namespace Regression\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Regression\SugarCRMScenario;

final class SugarCRMScenarioTest extends TestCase
{

    public function testExpectSubstring(): void
    {
        $request = new Request('GET', 'https://example.com');

        $token = uniqid();
        $responseHTML = <<<HTML
<html>
<form action="">
<input type="text" name="message" />
<input type="text" name="csrf_token" value="$token" />
<input type="submit" value="submit">
</form>
</html>
HTML;
        $response = new Response(200, [], $responseHTML);
        $client = $this->getMockBuilder(ClientMock::class)
            ->onlyMethods(['send'])
            ->getMock();

        $client->expects($this->once())
            ->method('send')
            ->will($this->returnValue($response));
        $scenario = $this->getMockForAbstractClass(SugarCRMScenario::class, [$client]);
        $scenario->send($request)
            ->extractCsrfToken();
        $this->assertEquals($token, $scenario->getVar('csrf_token'));
    }

}

class ClientMock extends Client
{
    public function get($uri)
    {
        return new Response(200, [], '{"serverUrl":"rest\/v11_15","siteUrl":""}');
    }
}