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
    public function getAssessmentDescription(): string
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
            ->assume(function (ResponseInterface $response) {
                if ($this->lastResponse->getStatusCode() !== 200) {
                    return false;
                }
                $json = json_decode((string)$response->getBody(), true);
                return !empty($json['sugar_version']);
            }, $knownSugarVersion)
            ->checkAssumptions('SugarCRM version disclosure via sugar_version.json', $knownSugarVersion);
    }
}
