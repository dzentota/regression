<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Regression\Assessment;
use Regression\Severity;

class DemoAssessment extends Assessment
{
    public function getSeverity(): ?string
    {
        return Severity::INFO;
    }

    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'SugarCRM version disclosure';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            '/sugar_version.json'
        );

        $this->send($request)
            ->expectStatusCode(200)
            ->expect(function (ResponseInterface $response) {
                $json = json_decode($response->getBody()->getContents(), true);
                return !empty($json['sugar_version']);
            }, 'SugarCRM version disclosure via sugar_version.json');
    }
}
