<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\SugarCRMScenario;

class LoginRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'Login failed';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            $this->prependBase('/me')
        );

        $this->login('admin', 'asdf')
            ->send($request)
            ->expectStatusCode(200)
            ->expectSubstring('"user_name":"admin"');
    }
}
