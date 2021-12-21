<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\Scenario;

class DemoRegression extends Scenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'Html page is not available';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            '/'
        );

        $this->send($request)
            ->expectStatusCode(200)
            ->expectSubstring('<title>');
    }
}
